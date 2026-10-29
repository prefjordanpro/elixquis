<?php
declare(strict_types=1);
namespace App\Tests;
use App\Entity\{Address, Carrier, CommercialSettings, Invoice, InvoiceLine, Order, Product, PromoCode, User};
use App\Service\{InvoicePdf, OrderManager};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InvoicingTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $user;
    private User $other;
    private User $admin;
    private Product $product;
    private Address $address;
    private Carrier $carrier;
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new User())->setEmail('invoice-client@example.test')->setFirstname('Alice')->setLastname('Client')->setPassword('unused');
        $this->other = (new User())->setEmail('invoice-other@example.test')->setFirstname('Autre')->setLastname('Client')->setPassword('unused');
        $this->admin = (new User())->setEmail('invoice-admin@example.test')->setFirstname('Paul')->setLastname('Admin')->setPassword('unused')->setRoles(['ROLE_ADMIN']);
        $this->product = (new Product())->setName('Recette originale')->setDescription('Test')->setIllustration('test.jpg')->setPrice(20)->setTva(20)->setStock(100);
        $this->address = (new Address())->setUser($this->user)->setFirstname('Alice')->setLastname('Client')->setAddress('1 rue Historique')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0600000000');
        $this->carrier = (new Carrier())->setName('Livraison originale')->setDescription('Standard')->setPrice(5)->setTva(20);
        foreach ([$this->user, $this->other, $this->admin, $this->product, $this->address, $this->carrier] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }
    private function manager(): OrderManager { return static::getContainer()->get(OrderManager::class); }
    private function order(int $qty = 1, ?string $code = null): Order
    {
        return $this->manager()->create($this->user, $this->address, $this->carrier, [['object'=>$this->product, 'qty'=>$qty]], null, $code);
    }
    private function session(Order $order): object
    {
        $order->setStripeSessionId('cs_invoice_test_'.$order->getId()); $this->em->flush();
        return (object) ['id'=>$order->getStripeSessionId(), 'payment_status'=>'paid', 'currency'=>'eur', 'amount_total'=>$order->getTotalCents(),
            'client_reference_id'=>(string) $order->getId(), 'payment_intent'=>'pi_invoice_test_'.$order->getId()];
    }
    private function pay(Order $order): Invoice
    {
        $this->manager()->confirmPayment($order, $this->session($order));
        return $this->em->getRepository(Invoice::class)->findOneBy(['order'=>$order, 'type'=>'invoice']);
    }
    private function refund(Order $order, string $status = 'succeeded'): object
    {
        return (object) ['id'=>'re_invoice_test_'.$order->getId(), 'payment_intent'=>$order->getStripePaymentIntentId(), 'status'=>$status, 'currency'=>'eur', 'amount'=>$order->getTotalCents(), 'reason'=>'requested_by_customer'];
    }
    private function export(string $name): void
    {
        if ($directory = getenv('INVOICE_REVIEW_DIR')) { if (!is_dir($directory)) mkdir($directory, 0777, true); file_put_contents($directory.'/'.$name.'.html', $this->client->getResponse()->getContent()); }
    }
    public function testPendingAndUnpaidCancellationDoNotIssueAccountingDocuments(): void
    {
        $order = $this->order(); self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
        $this->client->loginUser($this->user); $this->client->request('GET', '/compte');
        self::assertSelectorTextNotContains('main', 'Télécharger ma facture');
        $this->manager()->cancelUnpaid($order);
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }
    public function testConfirmedPaymentAndReplayCreateExactlyOneSequentialInvoice(): void
    {
        $order = $this->order(); $session = $this->session($order);
        $this->manager()->confirmPayment($order, $session); $this->manager()->confirmPayment($order, $session);
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['order'=>$order]);
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame($invoice, $order->getInitialInvoice());
        self::assertSame('FAC-'.date('Y').'-000001', $invoice->getNumber()); self::assertSame('Payée', $invoice->getStatus());
        self::assertNotNull($invoice->getPaidAt()); self::assertNull($invoice->getSaleAt()); self::assertSame(1, $invoice->getLines()->count());
        $second = $this->pay($this->order()); self::assertSame('FAC-'.date('Y').'-000002', $second->getNumber());
        self::assertGreaterThanOrEqual($invoice->getIssuedAt(), $second->getIssuedAt());
    }
    public function testSignedPaymentWebhooksAreIdempotent(): void
    {
        $order = $this->order(); $session = (array) $this->session($order); $session['object'] = 'checkout.session';
        $payload = json_encode(['id'=>'evt_invoice_test', 'object'=>'event', 'type'=>'checkout.session.completed', 'data'=>['object'=>$session]]);
        $time = time(); $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_tests_uniquement');
        for ($i=0; $i<3; ++$i) { $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE'=>$signature], $payload); self::assertResponseIsSuccessful(); }
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
    }
    public function testSnapshotsSurviveProductPromotionAddressAndSellerChanges(): void
    {
        $this->product->setPromotionPercent(25); $this->em->flush(); $order = $this->order(2);
        $this->product->setName('Nouveau nom')->setPrice(100)->setPromotionPercent(null)->setTva(5.5);
        $this->address->setAddress('99 nouvelle adresse')->setCity('Lyon'); $this->user->setFirstname('Nouveau')->setEmail('changed@example.test'); $this->carrier->setName('Autre livraison')->setPrice(50);
        $this->em->flush(); $invoice = $this->pay($order); $snapshot = $invoice->getSnapshot();
        self::assertSame('Recette originale', $snapshot['lines'][0]['name']); self::assertSame(1800, $snapshot['lines'][0]['unitGrossCents']);
        self::assertSame(1200, $snapshot['lines'][0]['productDiscountCents']); self::assertSame(2000, $snapshot['lines'][0]['rateBasisPoints']);
        self::assertSame('1 rue Historique', $snapshot['buyer']['address']); self::assertSame('invoice-client@example.test', $snapshot['buyer']['email']);
        self::assertSame('Alice', $snapshot['buyer']['firstname']); self::assertSame(4100, $invoice->getTotalGrossCents());
        $before = $_ENV['COMPANY_NAME'] ?? null;
        try {
            $_ENV['COMPANY_NAME'] = 'Société modifiée';
            $html = static::getContainer()->get(InvoicePdf::class)->html($invoice);
            self::assertStringContainsString($snapshot['seller']['legalName'], $html); self::assertStringNotContainsString('Société modifiée', $html);
        } finally { if ($before === null) unset($_ENV['COMPANY_NAME']); else $_ENV['COMPANY_NAME'] = $before; }
        self::assertSame($snapshot, $invoice->getSnapshot());
    }
    public function testProductPromotionAndFixedCouponUseTheFrozenAmounts(): void
    {
        $this->product->setPromotionPercent(25);
        $code = (new PromoCode())->setCode('FACTURE')->setName('Test')->setActive(true)->setType('fixed')->setValue(500)->setStackable(true);
        $this->em->persist($code); $this->em->flush(); $invoice = $this->pay($this->order(3, 'FACTURE')); $data = $invoice->getSnapshot();
        self::assertSame(5400, $invoice->getTotalGrossCents()); self::assertSame(4500, $invoice->getTotalNetCents()); self::assertSame(900, $invoice->getTotalTaxCents());
        self::assertSame(2300, $data['totals']['discountCents']); self::assertSame(500, $data['lines'][0]['couponDiscountCents']);
        self::assertSame(4083, $data['lines'][0]['netCents']); self::assertSame(817, $data['lines'][0]['taxCents']);
    }
    public function testPercentageCouponAndFreeShippingRemainExact(): void
    {
        $settings = (new CommercialSettings())->setFreeShippingMode('all');
        $code = (new PromoCode())->setCode('POURCENT')->setName('Test')->setActive(true)->setType('percent')->setValue(10);
        $this->em->persist($settings); $this->em->persist($code); $this->em->flush();
        $invoice = $this->pay($this->order(1, 'POURCENT')); $data = $invoice->getSnapshot();
        self::assertSame(2160, $invoice->getTotalGrossCents()); self::assertSame(1800, $invoice->getTotalNetCents()); self::assertSame(360, $invoice->getTotalTaxCents());
        self::assertSame(0, $data['shipping']['grossCents']); self::assertSame(500, $data['shipping']['logisticsCostCents']);
    }
    public function testNonStackableCouponDoesNotDiscountThePromotedProduct(): void
    {
        $this->product->setPromotionPercent(25);
        $second = (new Product())->setName('Sans promotion')->setDescription('Test')->setIllustration('test.jpg')->setPrice(10)->setTva(20)->setStock(5);
        $code = (new PromoCode())->setCode('NONCUMUL')->setName('Test')->setActive(true)->setType('percent')->setValue(10)->setStackable(false);
        $this->em->persist($second); $this->em->persist($code); $this->em->flush();
        $order = $this->manager()->create($this->user, $this->address, $this->carrier, [['object'=>$this->product, 'qty'=>1], ['object'=>$second, 'qty'=>1]], null, 'NONCUMUL');
        $data = $this->pay($order)->getSnapshot();
        self::assertSame([0, 120], array_column($data['lines'], 'couponDiscountCents')); self::assertSame(3380, $data['totals']['grossCents']);
    }
    public function testVatIsBrokenDownByTheActualRates(): void
    {
        $second = (new Product())->setName('Second taux')->setDescription('Test')->setIllustration('test.jpg')->setPrice(10)->setTva(5.5)->setStock(5);
        $this->em->persist($second); $this->em->flush();
        $order = $this->manager()->create($this->user, $this->address, $this->carrier, [['object'=>$this->product, 'qty'=>1], ['object'=>$second, 'qty'=>1]]);
        $invoice = $this->pay($order); $taxes = $invoice->getSnapshot()['taxes'];
        self::assertSame(3955, $invoice->getTotalGrossCents()); self::assertSame(3417, $invoice->getTotalNetCents()); self::assertSame(538, $invoice->getTotalTaxCents());
        self::assertSame([550, 2000], array_column($taxes, 'rateBasisPoints')); self::assertSame([55, 483], array_column($taxes, 'taxCents'));
    }
    public function testAFullRefundCreatesOneCreditAndLeavesTheInvoiceUnchanged(): void
    {
        $order = $this->order(); $invoice = $this->pay($order); $original = $invoice->getSnapshot();
        $refund = $this->refund($order); $this->manager()->synchronizeRefund($order, $refund); $this->manager()->synchronizeRefund($order, $refund);
        $credit = $this->em->getRepository(Invoice::class)->findOneBy(['order'=>$order, 'type'=>'credit']);
        self::assertSame(2, $this->em->getRepository(Invoice::class)->count([])); self::assertSame('AV-'.date('Y').'-000001', $credit->getNumber());
        self::assertSame($invoice->getId(), $credit->getOriginalInvoice()->getId()); self::assertSame($original, $invoice->getSnapshot());
        self::assertSame($invoice->getTotalGrossCents(), $credit->getTotalGrossCents()); self::assertSame($refund->id, $credit->getRefundReference());
        self::assertStringContainsString($invoice->getNumber(), static::getContainer()->get(InvoicePdf::class)->html($credit));
    }
    public function testSignedRefundWebhookReplayCreatesOnlyOneCredit(): void
    {
        $order = $this->order(); $invoice = $this->pay($order); $refund = (array) $this->refund($order); $refund['object'] = 'refund';
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? null; $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_invoice_simulated_only';
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(static function ($method, $url) use ($refund) {
            self::assertSame('get', $method); self::assertSame('/v1/refunds/'.$refund['id'], parse_url($url, PHP_URL_PATH));
            return [json_encode($refund), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        try {
            $payload = json_encode(['id'=>'evt_refund_invoice', 'object'=>'event', 'type'=>'refund.updated', 'data'=>['object'=>$refund]]);
            $time = time(); $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_tests_uniquement');
            for ($i=0; $i<2; ++$i) { $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE'=>$signature], $payload); self::assertResponseIsSuccessful(); }
            self::assertSame(1, $this->em->getRepository(Invoice::class)->count(['type'=>'credit']));
            self::assertSame(1, $this->em->getRepository(Invoice::class)->count(['type'=>'invoice']));
        } finally {
            \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
            if ($key === null) unset($_ENV['STRIPE_SECRET_KEY']); else $_ENV['STRIPE_SECRET_KEY'] = $key;
        }
    }
    public function testPendingFailedAndUnpaidRefundsDoNotCreateCredits(): void
    {
        $order = $this->order(); $this->pay($order);
        foreach (['pending', 'requires_action', 'failed', 'canceled'] as $status) $this->manager()->synchronizeRefund($order, $this->refund($order, $status));
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count(['type'=>'credit']));
    }
    public function testRefundCanUseTheVerifiedPaymentIntentResolvedAfterTheInvoice(): void
    {
        $order = $this->order(); $session = $this->session($order); unset($session->payment_intent);
        $this->manager()->confirmPayment($order, $session);
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['order'=>$order]);
        self::assertSame($session->id, $invoice->getPaymentReference());
        $order->setStripePaymentIntentId('pi_invoice_test_'.$order->getId()); $this->em->flush();
        $this->manager()->synchronizeRefund($order, $this->refund($order));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count(['type'=>'credit']));
        self::assertSame($session->id, $invoice->getPaymentReference());
    }
    public function testCancellationAfterPaymentKeepsInvoiceUntilTheConfirmedRefund(): void
    {
        $order = $this->order(); $invoice = $this->pay($order);
        $this->manager()->cancelForAdmin($order);
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        $this->manager()->synchronizeRefund($order, $this->refund($order));
        self::assertSame($invoice->getId(), $this->em->getRepository(Invoice::class)->findOneBy(['type'=>'credit'])->getOriginalInvoice()->getId());
    }
    public function testHistoricalPaidOrdersAreNotAutomaticallyInvoiced(): void
    {
        $order = $this->order(); $order->setState(1); $this->em->flush();
        self::assertNull($order->getPaymentConfirmedAt()); self::assertNull($order->getInitialInvoice());
        $this->client->loginUser($this->admin); $this->client->request('GET', '/admin/factures'); self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em->getRepository(Invoice::class)->count([]));
    }
    /** @dataProvider immutableChanges */
    public function testDocumentsAndLinesCannotBeChangedOrDeleted(string $kind): void
    {
        $invoice = $this->pay($this->order());
        if ($kind === 'delete') $this->em->remove($invoice);
        elseif ($kind === 'number') (new \ReflectionProperty(Invoice::class, 'number'))->setValue($invoice, 'FAC-MODIFIEE');
        else (new \ReflectionProperty(InvoiceLine::class, 'data'))->setValue($invoice->getLines()->first(), ['name'=>'MODIFIE']);
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('lecture seule'); $this->em->flush();
    }
    public static function immutableChanges(): array { return [['delete'], ['number'], ['line']]; }
    public function testExistingInvoiceCannotReceiveAnExtraLine(): void
    {
        $invoice = $this->pay($this->order());
        $this->em->persist(new InvoiceLine($invoice, 2, $invoice->getLines()->first()->getData()));
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('lecture seule'); $this->em->flush();
    }
    public function testDatabaseRejectsASecondInitialInvoiceForTheSameOrder(): void
    {
        $order = $this->order(); $invoice = $this->pay($order);
        $this->em->persist(new Invoice($order, 'FAC-2099-999999', $invoice->getIssuedAt(), $invoice->getSnapshot()));
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class); $this->em->flush();
    }
    public function testPdfUsesOnlySnapshotAndIsRegenerable(): void
    {
        $invoice = $this->pay($this->order()); $pdf = static::getContainer()->get(InvoicePdf::class);
        $html = $pdf->html($invoice);
        foreach ([$invoice->getNumber(), 'Alice', 'Recette originale', '29,00', 'Reste à payer : 0,00'] as $text) self::assertStringContainsString($text, $html);
        foreach (['sk_test_', 'sk_live_', 'APIKEY', 'STRIPE_SECRET', 'sendcloud.sc'] as $secret) self::assertStringNotContainsString($secret, $html);
        $bytes = $pdf->generate($invoice); self::assertStringStartsWith('%PDF-', $bytes); self::assertGreaterThan(5000, strlen($bytes));
        if ($directory = getenv('INVOICE_REVIEW_DIR')) { if (!is_dir($directory)) mkdir($directory, 0777, true); file_put_contents($directory.'/invoice.pdf', $bytes); }
        self::assertSame(1, preg_match_all('~/Type\s*/Page\b~', $bytes), 'A simple invoice must fit on one A4 page.');
        $alternate = new InvoicePdf(static::getContainer()->get('twig'), dirname(__DIR__).'/var/invoice-review/regenerated-'.bin2hex(random_bytes(4)));
        self::assertSame(hash('sha256', $bytes), hash('sha256', $alternate->generate($invoice)));
        $invoiceId = $invoice->getId(); $issuedAt = $invoice->getIssuedAt()->format(DATE_ATOM);
        $this->em->clear(); $reloaded = $this->em->find(Invoice::class, $invoiceId);
        self::assertSame($issuedAt, $reloaded->getIssuedAt()->format(DATE_ATOM));
        self::assertSame($html, $pdf->html($reloaded));
        if ($directory = getenv('INVOICE_REVIEW_DIR')) { if (!is_dir($directory)) mkdir($directory, 0777, true); file_put_contents($directory.'/invoice-pdf.html', $html); file_put_contents($directory.'/invoice.pdf', $bytes); }
    }
    public function testPdfFailureNeverAllocatesASecondInvoiceNumber(): void
    {
        $order = $this->order(); $invoice = $this->pay($order);
        $twig = $this->createMock(\Twig\Environment::class); $twig->method('render')->willThrowException(new \RuntimeException('PDF failure'));
        $pdf = new InvoicePdf($twig, dirname(__DIR__).'/var/invoice-review/failure-'.bin2hex(random_bytes(4)));
        try { $pdf->generate($invoice); self::fail('Rendering must fail.'); } catch (\RuntimeException $exception) { self::assertSame('PDF failure', $exception->getMessage()); }
        $this->manager()->confirmPayment($order, $this->session($order));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT invoice_value FROM invoice_sequence WHERE id = 1'));
    }
    public function testOwnerAdminOtherCustomerAndAnonymousPdfAccess(): void
    {
        $order = $this->order(); $invoice = $this->pay($order); $this->manager()->synchronizeRefund($order, $this->refund($order));
        $documents = $this->em->getRepository(Invoice::class)->findAll();
        $this->client->request('GET', '/compte/documents/'.$invoice->getId().'/pdf'); self::assertResponseRedirects('/connexion');
        $this->client->loginUser($this->other);
        foreach ($documents as $document) { $this->client->request('GET', '/compte/documents/'.$document->getId().'/pdf'); self::assertResponseStatusCodeSame(404); }
        $this->client->request('GET', '/admin/factures'); self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->user);
        foreach ($documents as $document) { $this->client->request('GET', '/compte/documents/'.$document->getId().'/pdf'); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Content-Type', 'application/pdf'); }
        $this->client->request('GET', '/compte'); self::assertSelectorTextContains('main', 'Télécharger ma facture'); $this->export('customer-orders');
        $this->client->request('GET', '/compte/commande/'.$order->getId()); self::assertSelectorTextContains('main', $invoice->getNumber()); $this->export('customer-order');
        $this->client->loginUser($this->admin); $this->client->request('GET', '/admin/factures/document/'.$invoice->getId().'/pdf'); self::assertResponseIsSuccessful();
    }
    public function testAdminSearchFiltersAndReadOnlyDetails(): void
    {
        $invoice = $this->pay($this->order()); $this->client->loginUser($this->admin);
        foreach ([$invoice->getNumber(), 'Alice', $invoice->getSnapshot()['orderReference']] as $search) {
            $this->client->request('GET', '/admin/factures', ['q'=>$search]); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('table', $invoice->getNumber());
        }
        $this->export('admin-invoices');
        $this->client->request('GET', '/admin/factures', ['type'=>'credit']); self::assertSelectorTextNotContains('table', $invoice->getNumber());
        $this->client->request('GET', '/admin/factures', ['from'=>'2099-01-01']); self::assertSelectorTextNotContains('table', $invoice->getNumber());
        $this->client->request('GET', '/admin/factures/'.$invoice->getId()); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('h1', $invoice->getNumber());
        self::assertSelectorNotExists('form'); self::assertSelectorNotExists('a[href$="/edit"]'); $this->export('admin-invoice');
    }
}
