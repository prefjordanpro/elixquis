<?php
namespace App\Tests;

use App\Classe\Mail;
use App\Entity\{Order, OrderDetail, Product, Shipment, User};
use App\Service\{OrderEmails, OrderManager};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderEmailsTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private Order $order;
    private Product $product;
    private OrderManager $orders;
    private OrderEmails $emails;
    private array $sent = [];
    private bool $failMail = false;
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $mail = $this->createMock(Mail::class);
        $mail->method('sendEmail')->willReturnCallback(function ($email) {
            $this->sent[] = $email;
            if ($this->failMail) { throw new \RuntimeException('private-provider-token'); }
            return ['message_id' => 'mock'];
        });
        static::getContainer()->set(Mail::class, $mail);
        $this->emails = new OrderEmails($this->em, $mail, static::getContainer()->get('twig'),
            static::getContainer()->get('router'), new NullLogger(), 'https://shop.example.test', 'sender@example.test');
        $this->orders = new OrderManager($this->em, $this->emails, static::getContainer()->get(\App\Service\InvoiceIssuer::class));
        $user = (new User())->setEmail('julie@example.test')->setFirstname('Julie')->setLastname('Martin')->setPassword('unused');
        $this->product = (new Product())->setName('Rhum')->setSlug('rhum')->setDescription('Test')->setIllustration('test.jpg')->setPrice(10)->setTva(20)->setStock(8);
        $this->order = (new Order())->setUser($user)->setCreatedAt(new \DateTime())->setState(0)
            ->setCarrierName('Transporteur test')->setCarrierPrice(5)->setDelivery("Julie Martin\n10 rue des Fleurs\n75001 Paris")
            ->setStockReserved(true)->setStripeSessionId('cs_order_email');
        $this->order->addOrderDetail((new OrderDetail())->setProduct($this->product)->setProductName('Rhum')
            ->setProductPrice(12)->setProductTva(20)->setProductQuantity(2)->setProductIllustration('test.jpg'));
        foreach ([$user, $this->product, $this->order] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
    }
    private function session(): object
    {
        return (object) ['id' => 'cs_order_email', 'payment_status' => 'paid', 'currency' => 'eur',
            'amount_total' => 2900, 'client_reference_id' => (string) $this->order->getId(), 'payment_intent' => 'pi_order_email'];
    }
    private function paid(): void { $this->orders->confirmPayment($this->order, $this->session()); }
    private function receiptCount(): int { return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM order_notification'); }

    public function testPaymentConfirmationAndReplaySendOnce(): void
    {
        $this->paid(); $this->paid(); self::assertSame(1, $this->order->getState()); self::assertSame(1, $this->receiptCount());
        self::assertSame(1, $this->emails->dispatch()); self::assertSame(0, $this->emails->dispatch()); self::assertCount(1, $this->sent);
        $text = $this->sent[0]->getTextBody();
        foreach (['Julie', $this->order->getReference(), 'Rhum', 'quantité 2', '29,00', 'Transporteur test', '75001 Paris', 'https://shop.example.test/compte'] as $value) { self::assertStringContainsString($value, $text); }
        self::assertStringContainsString('Elixquis', $this->sent[0]->getHtmlBody());
    }
    public function testSignedWebhookTwiceProducesOneEmail(): void
    {
        $payload = json_encode(['id' => 'evt_email', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => (array) $this->session() + ['object' => 'checkout.session']]]);
        $time = time(); $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_tests_uniquement');
        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload);
            self::assertResponseIsSuccessful();
        }
        self::assertSame(1, $this->receiptCount()); self::assertSame(1, $this->emails->dispatch());
        self::assertSame(0, $this->emails->dispatch()); self::assertCount(1, $this->sent);
    }
    public function testLogisticsAndDuplicateActionsNeverChangeStock(): void
    {
        $this->paid();
        foreach ([[1, 2], [2, 3], [3, 5]] as [$from, $to]) {
            $this->orders->advance($this->order, $from, $to); $this->orders->advance($this->order, $from, $to);
            self::assertSame($to, $this->order->getState()); self::assertSame(8, $this->product->getStock());
            self::assertTrue($this->order->isStockReserved());
        }
        self::assertSame(4, $this->receiptCount()); self::assertSame(4, $this->emails->dispatch());
        self::assertSame(0, $this->emails->dispatch());
    }
    public function testForbiddenTransitionIsRejected(): void
    {
        $this->paid();
        try { $this->orders->advance($this->order, 1, 5); self::fail('Transition interdite'); }
        catch (\DomainException $e) { self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`')); }
        self::assertSame(1, $this->receiptCount());
    }
    public function testUnpaidCancellationHasNoRefundMention(): void
    {
        $this->orders->cancelUnpaid($this->order); $this->orders->cancelUnpaid($this->order);
        self::assertSame(4, $this->order->getState()); self::assertSame(1, $this->emails->dispatch());
        self::assertStringNotContainsString('rembours', $this->sent[0]->getTextBody());
        self::assertStringNotContainsString('rembours', $this->sent[0]->getHtmlBody());
    }
    public function testStripeRefundConfirmationAndReplay(): void
    {
        $this->paid();
        $refund = (object) ['id' => 're_email', 'payment_intent' => 'pi_order_email', 'currency' => 'eur', 'amount' => 2900, 'status' => 'succeeded'];
        $this->orders->synchronizeRefund($this->order, $refund); $this->orders->synchronizeRefund($this->order, $refund);
        self::assertSame(6, $this->order->getState()); self::assertSame(2, $this->emails->dispatch());
        self::assertStringContainsString('délai bancaire peut dépendre', $this->sent[1]->getTextBody());
        $this->paid(); self::assertSame(6, $this->order->getState()); self::assertSame(0, $this->emails->dispatch());
    }
    /** @dataProvider trackingCases */
    public function testShippingWithOrWithoutTracking(bool $withTracking): void
    {
        $this->paid(); $this->orders->advance($this->order, 1, 2);
        if ($withTracking) {
            $shipment = new Shipment($this->order);
            $shipment->synchronize(['id' => 'shipment_mock', 'parcels' => [['id' => 1, 'tracking_number' => 'TRACK123', 'tracking_url' => 'https://tracking.example.test/123']]]);
            $this->em->persist($shipment); $this->em->flush();
        }
        $this->orders->advance($this->order, 2, 3); $this->emails->dispatch();
        $text = $this->sent[2]->getTextBody(); self::assertStringContainsString('Transporteur test', $text);
        if ($withTracking) {
            self::assertStringContainsString('TRACK123', $text); self::assertStringContainsString('https://tracking.example.test/123', $text);
        } else { self::assertStringNotContainsString('Numéro de suivi', $text); self::assertStringNotContainsString('Suivre votre colis', $text); }
    }
    public static function trackingCases(): array { return [[true], [false]]; }
    public function testMailFailurePreservesPaidStatusAndIsNotRetried(): void
    {
        $this->paid(); $this->failMail = true;
        self::assertSame(0, $this->emails->dispatch()); self::assertSame(0, $this->emails->dispatch()); self::assertCount(1, $this->sent);
        self::assertSame(1, $this->order->getState()); self::assertTrue($this->order->isStockReserved());
        self::assertSame('failed', $this->em->getConnection()->fetchOne('SELECT status FROM order_notification'));
    }
    public function testHistoricalStatusRendersWithoutCrash(): void
    {
        $html = static::getContainer()->get('twig')->render('admin/state.html.twig', ['field' => ['value' => 99]]);
        self::assertStringContainsString('Statut à vérifier', $html);
    }
    public function testDispatchWaitsForOuterTransaction(): void
    {
        $db = $this->em->getConnection(); $db->beginTransaction();
        $this->paid(); self::assertSame(0, $this->emails->dispatch()); self::assertCount(0, $this->sent);
        $db->rollBack(); self::assertSame(0, $this->receiptCount()); self::assertSame(0, $this->emails->dispatch());
    }
    public function testUncertainAttemptIsNeverAutomaticallyReplayed(): void
    {
        $this->paid(); $this->em->getConnection()->executeStatement("UPDATE order_notification SET status = 'attempting'");
        self::assertSame(0, $this->emails->dispatch()); self::assertCount(0, $this->sent);
    }
    public function testPendingRefundDoesNotAnnounceACompletedRefund(): void
    {
        $this->paid();
        $this->orders->synchronizeRefund($this->order, (object) ['id' => 're_email', 'payment_intent' => 'pi_order_email',
            'currency' => 'eur', 'amount' => 2900, 'status' => 'pending']);
        self::assertSame(1, $this->order->getState()); self::assertSame(1, $this->receiptCount());
        self::assertNull($this->order->getLogisticsTarget());
    }
    public function testQueuedEmailUsesImmutableSnapshot(): void
    {
        $this->paid();
        $this->order->setDelivery('Adresse modifiée')->setCarrierName('Autre transporteur');
        $this->order->getOrderDetails()->first()->setProductName('Produit modifié'); $this->em->flush();
        $this->emails->dispatch(); $text = $this->sent[0]->getTextBody();
        self::assertStringContainsString('75001 Paris', $text); self::assertStringContainsString('Rhum', $text);
        self::assertStringNotContainsString('Adresse modifiée', $text);
    }
    public function testProviderExceptionIsNotExposedInLogs(): void
    {
        $this->paid();
        $mail = $this->createMock(Mail::class);
        $mail->method('sendEmail')->willThrowException(new \RuntimeException('private-provider-token julie@example.test'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Courriel transactionnel commande indisponible.',
            ['order' => $this->order->getId(), 'notification' => 1]);
        $dispatcher = new OrderEmails($this->em, $mail, static::getContainer()->get('twig'),
            static::getContainer()->get('router'), $logger, 'https://shop.example.test', 'sender@example.test');
        self::assertSame(0, $dispatcher->dispatch()); self::assertSame(1, $this->order->getState());
    }
    public function testMatrixRejectsBackwardsAndHistoricalTransitions(): void
    {
        foreach ([[5, 3], [6, 1], [6, 2], [0, 2], [1, 3], [7, 2], [99, 2]] as [$from, $to]) {
            self::assertFalse(OrderManager::allows('logistics', $from, $to));
        }
        self::assertFalse(OrderManager::allows('payment', 6, 1));
        self::assertFalse(OrderManager::allows('admin_cancellation', 5, 4));
        self::assertFalse(OrderManager::allows('refund', 0, 6));
        foreach ([[1, 2], [2, 3], [3, 5]] as [$from, $to]) { self::assertTrue(OrderManager::allows('logistics', $from, $to)); }
    }
}
