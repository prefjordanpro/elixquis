<?php
namespace App\Tests;

use App\Entity\{Address, Carrier, Order, Product, PromoCode, User};
use App\Service\{CommercialPricing, OrderManager, StripePayment};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Paiements simulés uniquement ; tous les appels Stripe non prévus font échouer le test. */
class PromoCodeUsageTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private PromoCode $code;
    private User $user;
    private Address $address;
    private Carrier $carrier;
    private Product $product;
    private ?string $previousKey;
    protected function setUp(): void
    {
        $this->previousKey = $_ENV['STRIPE_SECRET_KEY'] ?? null; $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_usage_mock_only';
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $this->user = (new User())->setEmail('promo-usage@example.test')->setFirstname('Test')->setLastname('Usage')->setPassword('hash');
        $this->address = (new Address())->setUser($this->user)->setFirstname('Test')->setLastname('Usage')->setAddress('1 rue de Test')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $this->carrier = (new Carrier())->setName('Test')->setDescription('Test')->setPrice(5)->setTva(20);
        $this->product = (new Product())->setName('Test')->setSlug('test-usage')->setDescription('Test')->setIllustration('test.jpg')->setPrice(20)->setTva(20)->setStock(20);
        $this->code = (new PromoCode())->setCode('PAIDONLY')->setName('Paiement confirmé')->setActive(true)->setType('percent')->setValue(10)->setUsageLimit(1);
        foreach ([$this->user, $this->address, $this->carrier, $this->product, $this->code] as $entity) { $this->em->persist($entity); } $this->em->flush();
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class); $http->expects(self::never())->method('request'); \Stripe\ApiRequestor::setHttpClient($http);
    }
    protected function tearDown(): void
    {
        \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
        if ($this->previousKey === null) { unset($_ENV['STRIPE_SECRET_KEY']); } else { $_ENV['STRIPE_SECRET_KEY'] = $this->previousKey; }
        parent::tearDown();
    }
    private function order(): Order
    {
        return static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]], null, 'PAIDONLY');
    }
    private function session(Order $order, array $overrides = []): array
    {
        return array_replace(['id' => $order->getStripeSessionId(), 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
            'currency' => 'eur', 'amount_total' => $order->getTotalCents(), 'client_reference_id' => (string) $order->getId()], $overrides);
    }
    private function prepare(Order $order): void { $order->setStripeSessionId('cs_usage_'.$order->getId()); $this->em->flush(); }
    private function webhook(Order $order, array $overrides = [], string $type = 'checkout.session.completed', bool $validSignature = true): void
    {
        $payload = json_encode(['id' => 'evt_usage_'.$order->getId(), 'object' => 'event', 'type' => $type, 'data' => ['object' => $this->session($order, $overrides)]]);
        $time = time(); $signature = $validSignature ? 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_tests_uniquement') : 'invalid';
        $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }
    private function uses(): int { return (int) $this->em->getConnection()->fetchOne('SELECT uses FROM promo_code WHERE id = ?', [$this->code->getId()]); }
    public function testPendingOrdersDoNotConsumeUsesOrExhaustTheCode(): void
    {
        $first = $this->order(); $second = $this->order();
        self::assertSame(0, $first->getState()); self::assertSame(0, $second->getState()); self::assertSame(0, $this->uses());
        self::assertNull($first->getPromoUsageCountedAt()); self::assertTrue($this->code->isAvailable());
    }
    public function testTwoDifferentPaidOrdersEachCountOnce(): void
    {
        $this->code->setUsageLimit(null); $this->em->flush();
        $first = $this->order(); $second = $this->order(); $this->prepare($first); $this->prepare($second);
        $manager = static::getContainer()->get(OrderManager::class);
        $manager->confirmPayment($first, (object) $this->session($first));
        $manager->confirmPayment($second, (object) $this->session($second));
        $manager->confirmPayment($first, (object) $this->session($first));
        self::assertSame(2, $this->uses());
    }
    public function testConfirmedSignedWebhookCountsExactlyOnce(): void
    {
        $order = $this->order(); $this->prepare($order); $before = $order->getTotalCents();
        $this->webhook($order); self::assertResponseIsSuccessful(); self::assertSame(1, $this->uses());
        $stamp = $this->em->getConnection()->fetchOne('SELECT promo_usage_counted_at FROM `order` WHERE id = ?', [$order->getId()]); self::assertNotEmpty($stamp);
        $this->webhook($order); self::assertResponseIsSuccessful(); self::assertSame(1, $this->uses());
        self::assertSame($stamp, $this->em->getConnection()->fetchOne('SELECT promo_usage_counted_at FROM `order` WHERE id = ?', [$order->getId()]));
        $saved = $this->em->find(Order::class, $order->getId()); self::assertSame(1, $saved->getState()); self::assertSame($before, $saved->getTotalCents());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM order_notification'));
        self::assertFalse($this->em->find(PromoCode::class, $this->code->getId())->isAvailable());
    }
    public function testExpiryBeforeStartingStripeDoesNotConsumeUse(): void
    {
        $order = $this->order(); $order->setCreatedAt(new \DateTime('-25 hours')); $this->em->flush();
        self::assertSame('cancelled_uninitiated', static::getContainer()->get(StripePayment::class)->expireUnpaid($order));
        self::assertSame(0, $this->uses()); self::assertNull($order->getPromoUsageCountedAt()); self::assertTrue($this->code->isAvailable());
    }
    public function testExpiredStripeSessionDoesNotConsumeUse(): void
    {
        $order = $this->order(); $this->prepare($order);
        $session = $this->session($order, ['status' => 'expired', 'payment_status' => 'unpaid']);
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([json_encode($session), 200, []]); \Stripe\ApiRequestor::setHttpClient($http);
        $this->webhook($order, ['status' => 'expired', 'payment_status' => 'unpaid'], 'checkout.session.expired'); self::assertResponseIsSuccessful();
        $this->webhook($order, ['status' => 'expired', 'payment_status' => 'unpaid'], 'checkout.session.expired'); self::assertResponseIsSuccessful();
        self::assertSame(0, $this->uses()); self::assertSame(4, $this->em->find(Order::class, $order->getId())->getState());
        self::assertNull($this->em->find(Order::class, $order->getId())->getPromoUsageCountedAt());
    }
    public function testInvalidOrUnpaidEventsNeverCount(): void
    {
        $order = $this->order(); $this->prepare($order);
        $this->webhook($order, [], 'checkout.session.completed', false); self::assertResponseStatusCodeSame(400); self::assertSame(0, $this->uses());
        $this->webhook($order, ['payment_status' => 'unpaid']); self::assertResponseIsSuccessful(); self::assertSame(0, $this->uses());
        $this->webhook($order, ['amount_total' => 1]); self::assertResponseStatusCodeSame(409); self::assertSame(0, $this->uses());
        self::assertSame(0, $this->em->find(Order::class, $order->getId())->getState());
    }
    public function testSuccessVerificationThenWebhookRemainIdempotent(): void
    {
        $order = $this->order(); $this->prepare($order);
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([json_encode($this->session($order)), 200, []]); \Stripe\ApiRequestor::setHttpClient($http);
        static::getContainer()->get(StripePayment::class)->verify($order); self::assertSame(1, $this->uses());
        $this->webhook($order); self::assertResponseIsSuccessful(); self::assertSame(1, $this->uses());
    }
    public function testRenamedOrDisabledCodeCountsTheOriginalDefinition(): void
    {
        $order = $this->order(); $this->prepare($order);
        $this->code->setCode('RENAMED')->setActive(false); $this->em->flush();
        $this->webhook($order); self::assertResponseIsSuccessful(); self::assertSame(1, $this->uses());
        self::assertSame('PAIDONLY', $this->em->find(Order::class, $order->getId())->getPromoCode());
    }
    public function testRecreatedCodeCannotReceiveAnOldOrdersUsage(): void
    {
        $order = $this->order(); $this->prepare($order); $this->em->remove($this->code); $this->em->flush(); $this->em->clear();
        $replacement = (new PromoCode())->setCode('PAIDONLY')->setName('Nouvelle définition')->setActive(true);
        $this->em->persist($replacement); $this->em->flush();
        $this->webhook($order); self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em->find(PromoCode::class, $replacement->getId())->getUses());
        self::assertNotNull($this->em->find(Order::class, $order->getId())->getPromoUsageCountedAt());
    }
    public function testMigrationRecountsHistoricalPaidOrdersAndLeavesPendingOrdersFree(): void
    {
        $paid = $this->order(); $pending = $this->order(); $cancelled = $this->order();
        $paid->setState(1); $cancelled->setState(4);
        foreach ([$paid, $pending, $cancelled] as $order) { $order->setPromoCodeDefinition(null); }
        for ($i = 0; $i < 3; ++$i) { $this->code->recordConfirmedUse(); } // Ancien compteur à la création.
        $this->em->flush();
        $before = array_map(static fn ($order) => $order->getTotalCents(), [$paid, $pending, $cancelled]);
        require_once dirname(__DIR__).'/migrations/Version20261018131227.php';
        foreach (\DoctrineMigrations\Version20261018131227::RECONCILIATION_SQL as $sql) { $this->em->getConnection()->executeStatement($sql); }
        $this->em->clear(); self::assertSame(1, $this->uses());
        foreach ([$paid, $pending, $cancelled] as $index => $order) {
            $saved = $this->em->find(Order::class, $order->getId());
            self::assertSame($before[$index], $saved->getTotalCents());
            self::assertSame($index === 0, $saved->getPromoUsageCountedAt() !== null);
        }
        $this->prepare($this->em->find(Order::class, $paid->getId()));
        $this->webhook($this->em->find(Order::class, $paid->getId())); self::assertResponseIsSuccessful(); self::assertSame(1, $this->uses());
    }
}
