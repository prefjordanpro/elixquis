<?php
declare(strict_types=1);
namespace App\Tests;

use App\Command\ExpireOrdersCommand;
use App\Entity\{Address, Carrier, Order, Product, User};
use App\Repository\OrderRepository;
use App\Service\{OrderManager, StripePayment};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Stripe\ApiRequestor;
use Stripe\HttpClient\{ClientInterface, CurlClient};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ExpiredPaymentsTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private Order $order;
    private Product $product;
    private array $requests = [];
    private ?string $previousKey;

    protected function setUp(): void
    {
        $this->previousKey = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_expiration_mock_only';
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new User())->setEmail('expiration@example.test')->setFirstname('Test')->setLastname('Test')->setPassword('hash');
        $address = (new Address())->setUser($user)->setFirstname('Test')->setLastname('Test')->setAddress('Test')
            ->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $this->product = (new Product())->setName('Test')->setSlug('test')->setDescription('Test')->setIllustration('test.jpg')
            ->setPrice(20)->setTva(20)->setStock(10);
        $carrier = (new Carrier())->setName('Test')->setDescription('Test')->setPrice(5)->setTva(20);
        foreach ([$user, $address, $this->product, $carrier] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        $this->order = static::getContainer()->get(OrderManager::class)->create($user, $address, $carrier,
            [['object' => $this->product, 'qty' => 2]]);
        $this->order->setPaymentTrackingEnabled(false)->setCreatedAt(new \DateTime('-25 hours'))->setStripeSessionId('cs_expiration'); $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params, $files) {
            self::assertNotEmpty($this->requests, 'Appel Stripe imprévu interdit.');
            [$expectedMethod, $path, $response, $code, $check] = array_shift($this->requests);
            self::assertSame($expectedMethod, $method); self::assertSame($path, parse_url($url, PHP_URL_PATH));
            if ($check) { $check($params, $headers); }
            return [json_encode($response), $code, []];
        });
        ApiRequestor::setHttpClient($http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        if ($this->previousKey === null) { unset($_ENV['STRIPE_SECRET_KEY']); }
        else { $_ENV['STRIPE_SECRET_KEY'] = $this->previousKey; }
        parent::tearDown();
    }

    private function session(string $status = 'expired', string $payment = 'unpaid', ?string $intent = null): array
    {
        return ['id' => 'cs_expiration', 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $payment,
            'payment_intent' => $intent, 'client_reference_id' => (string) $this->order->getId(),
            'currency' => 'eur', 'amount_total' => $this->order->getTotalCents()];
    }

    private function expectSession(array $session, string $method = 'get'): void
    {
        $this->requests[] = [$method, '/v1/checkout/sessions/cs_expiration'.($method === 'post' ? '/expire' : ''), $session, 200, null];
    }

    private function runCommand(): int
    {
        return (new CommandTester(new ExpireOrdersCommand(static::getContainer()->get(OrderRepository::class),
            static::getContainer()->get(StripePayment::class))))->execute([]);
    }

    private function assertOrder(int $state, int $stock): void
    {
        self::assertSame($state, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        self::assertSame($stock, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
        self::assertSame([], $this->requests);
        self::assertNull($this->order->getStripeRefundId());
    }

    public function testRecentOrderIsNotProcessed(): void
    {
        $this->order->setCreatedAt(new \DateTime('-23 hours')); $this->em->flush();
        self::assertSame(0, $this->runCommand()); $this->assertOrder(0, 8);
    }

    private function trackedWithoutSession(string $age = '-25 hours'): void
    {
        $this->order->setPaymentTrackingEnabled(true)->setStripeSessionId(null)->setCreatedAt(new \DateTime($age));
        $this->em->flush();
    }

    public function testCreationExplicitlyTracksNeverStartedPayment(): void
    {
        $created = static::getContainer()->get(OrderManager::class)->create($this->order->getUser(),
            static::getContainer()->get(\App\Repository\AddressRepository::class)->findOneBy([]),
            static::getContainer()->get(\App\Repository\CarrierRepository::class)->findOneBy([]),
            [['object' => $this->product, 'qty' => 1]]);
        $this->em->refresh($created);
        self::assertTrue($created->isPaymentTrackingEnabled());
        self::assertNull($created->getPaymentInitiatedAt());
        self::assertNull($created->getStripeSessionId());
        self::assertNull($created->getStripeCheckoutRequest());
    }

    public function testRecentNeverStartedPaymentIsPreserved(): void
    {
        $this->trackedWithoutSession('-23 hours');
        self::assertSame(0, $this->runCommand()); $this->assertOrder(0, 8);
    }

    public function testOldNeverStartedPaymentIsCancelledOnlyOnce(): void
    {
        $this->trackedWithoutSession();
        self::assertSame(0, $this->runCommand()); self::assertSame(0, $this->runCommand());
        self::assertSame('unchanged', static::getContainer()->get(StripePayment::class)->expireUnpaid($this->order, true));
        $this->assertOrder(4, 10); self::assertFalse($this->order->isStockReserved());
    }

    public function testNeverStartedDryRunDoesNotPersistAnything(): void
    {
        $this->trackedWithoutSession();
        $tester = new CommandTester(new ExpireOrdersCommand(static::getContainer()->get(OrderRepository::class), static::getContainer()->get(StripePayment::class)));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('paiement jamais initié depuis >24 h', $tester->getDisplay());
        $this->assertOrder(0, 8);
    }

    public function testUncertainAttemptNeverCancelsInRealOrDryRun(): void
    {
        $this->trackedWithoutSession(); $this->order->recordPaymentAttempt(['client_reference_id' => (string) $this->order->getId()]); $this->em->flush();
        $tester = new CommandTester(new ExpireOrdersCommand(static::getContainer()->get(OrderRepository::class), static::getContainer()->get(StripePayment::class)));
        foreach ([true, false] as $dryRun) {
            self::assertSame(0, $tester->execute($dryRun ? ['--dry-run' => true] : []));
            self::assertStringContainsString('état incertain', $tester->getDisplay());
        }
        self::assertFalse(static::getContainer()->get(\App\Service\HistoricalOrderCancellation::class)->isEligible($this->order));
        $this->assertOrder(0, 8);
    }

    public function testPaidTrackedOrderWithoutSessionNeverCancels(): void
    {
        $this->trackedWithoutSession(); $this->order->setState(1); $this->em->flush();
        self::assertSame(0, $this->runCommand()); $this->assertOrder(1, 8);
    }

    public function testCheckoutCommitsAttemptBeforeStripeAndImmediatelyStoresSession(): void
    {
        $this->trackedWithoutSession('-1 hour');
        $this->requests[] = ['post', '/v1/checkout/sessions', ['id' => 'cs_expiration', 'object' => 'checkout.session', 'url' => 'https://checkout.example.test'], 200,
            function ($params, $headers) {
                self::assertNotNull($this->em->getConnection()->fetchOne('SELECT payment_initiated_at FROM `order`'));
                self::assertSame((string) $this->order->getId(), $params['client_reference_id']);
                self::assertSame((string) $this->order->getId(), $params['metadata']['order_id']);
                self::assertStringContainsString('checkout-order-'.$this->order->getId(), implode(' ', $headers));
            }];
        $payment = static::getContainer()->get(StripePayment::class);
        self::assertSame('https://checkout.example.test', $payment->checkout($this->order));
        self::assertSame('cs_expiration', $this->em->getConnection()->fetchOne('SELECT stripe_session_id FROM `order`'));
        $this->expectSession($this->session('open') + ['url' => 'https://checkout.example.test']);
        self::assertSame('https://checkout.example.test', $payment->checkout($this->order));
        $this->assertOrder(0, 8);
    }

    public function testTimeoutPersistsAttemptAndRetryUsesIdenticalRequestAndKey(): void
    {
        $this->trackedWithoutSession('-1 hour'); $firstParams = null; $firstHeaders = null;
        $this->requests[] = ['post', '/v1/checkout/sessions', [], 200,
            function ($params, $headers) use (&$firstParams, &$firstHeaders) {
                $firstParams = $params; $firstHeaders = $headers;
                throw new \Stripe\Exception\ApiConnectionException('Mock timeout after remote creation');
            }];
        try { static::getContainer()->get(StripePayment::class)->checkout($this->order); self::fail('Timeout attendu'); }
        catch (\Stripe\Exception\ApiConnectionException $e) { self::assertSame('Mock timeout after remote creation', $e->getMessage()); }
        $connection = $this->em->getConnection();
        self::assertNotNull($connection->fetchOne('SELECT payment_initiated_at FROM `order`'));
        self::assertNull($connection->fetchOne('SELECT stripe_session_id FROM `order`'));
        // Simulate a new HTTP request after Doctrine closed the failed transaction's manager.
        $this->em = new \Doctrine\ORM\EntityManager($connection, $this->em->getConfiguration());
        $this->order = $this->em->find(Order::class, $this->order->getId());
        $this->order->getUser()->setEmail('changed-after-timeout@example.test'); $this->em->flush();
        $emails = new \App\Service\OrderEmails($this->em, $this->createMock(\App\Classe\Mail::class),
            static::getContainer()->get('twig'), static::getContainer()->get('router'), new \Psr\Log\NullLogger(),
            'https://shop.example.test', 'sender@example.test');
        $payment = new StripePayment($this->em, static::getContainer()->get('router'), new OrderManager($this->em, $emails));
        $this->requests[] = ['post', '/v1/checkout/sessions', ['id' => 'cs_expiration', 'object' => 'checkout.session', 'url' => 'https://checkout.example.test'], 200,
            function ($params, $headers) use (&$firstParams, &$firstHeaders) {
                self::assertSame($firstParams, $params);
                self::assertSame(array_values(array_filter($firstHeaders, fn ($v) => str_contains($v, 'Idempotency-Key'))),
                    array_values(array_filter($headers, fn ($v) => str_contains($v, 'Idempotency-Key'))));
            }];
        self::assertSame('https://checkout.example.test', $payment->checkout($this->order));
        $this->assertOrder(0, 8);
    }

    public function testOldUncertainAttemptCannotBeReplayed(): void
    {
        $this->trackedWithoutSession(); $this->order->recordPaymentAttempt([]); $this->em->flush();
        try { static::getContainer()->get(StripePayment::class)->checkout($this->order); self::fail('Refus attendu'); }
        catch (\DomainException $e) { self::assertNotEmpty($e->getMessage()); }
        $this->assertOrder(0, 8);
    }

    public static function cronWebhookOrders(): iterable
    {
        yield 'cron puis webhook' => [false];
        yield 'webhook puis cron' => [true];
    }

    /** @dataProvider cronWebhookOrders */
    public function testTrackedSessionCronAndWebhookShareIdempotentCancellation(bool $webhookFirst): void
    {
        $this->trackedWithoutSession();
        $this->order->recordPaymentAttempt(['client_reference_id' => (string) $this->order->getId()]);
        $this->order->setStripeSessionId('cs_expiration'); $this->em->flush();
        $this->expectSession($this->session());
        if ($webhookFirst) {
            $this->webhook($this->session()); self::assertResponseIsSuccessful();
            self::assertSame(0, $this->runCommand());
        } else {
            self::assertSame(0, $this->runCommand());
            $this->webhook($this->session()); self::assertResponseIsSuccessful();
        }
        $this->webhook($this->session()); self::assertResponseIsSuccessful();
        self::assertSame(0, $this->runCommand()); $this->assertOrder(4, 10);
    }

    public function testOpenUnpaidSessionExpiresAndRepeatedCommandDoesNotRestockTwice(): void
    {
        $this->expectSession($this->session('open'));
        $this->expectSession($this->session(), 'post');
        self::assertSame(0, $this->runCommand());
        self::assertSame(0, $this->runCommand());
        $this->assertOrder(4, 10); self::assertFalse($this->order->isStockReserved());
    }

    public function testAlreadyExpiredSessionIsCancelled(): void
    {
        $this->expectSession($this->session()); self::assertSame(0, $this->runCommand()); $this->assertOrder(4, 10);
    }

    public function testOrderWithoutSessionIsPreservedOnRepeatedRuns(): void
    {
        $this->order->setStripeSessionId(null); $this->em->flush();
        self::assertSame(0, $this->runCommand()); self::assertSame(0, $this->runCommand()); $this->assertOrder(0, 8);
        self::assertTrue($this->order->isStockReserved());
    }

    public function testPaidSessionSynchronizesRatherThanCancelling(): void
    {
        $this->expectSession($this->session('complete', 'paid', 'pi_expiration'));
        self::assertSame(0, $this->runCommand()); $this->assertOrder(1, 8);
        self::assertSame('pi_expiration', $this->order->getStripePaymentIntentId());
    }

    public static function protectedStates(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 6, 7] as $state) { yield [$state]; }
    }

    /** @dataProvider protectedStates */
    public function testOtherStatesNeverChange(int $state): void
    {
        $this->order->setState($state); $this->em->flush();
        self::assertSame(0, $this->runCommand()); $this->assertOrder($state, 8);
    }

    public function testCompleteUnpaidSessionIsPreserved(): void
    {
        $this->expectSession($this->session('complete'));
        self::assertSame(0, $this->runCommand()); $this->assertOrder(0, 8);
    }

    public static function activeIntents(): iterable
    {
        foreach (['processing', 'requires_action', 'requires_confirmation', 'requires_capture', 'succeeded'] as $state) { yield [$state]; }
    }

    /** @dataProvider activeIntents */
    public function testPaymentIntentInProgressOrSucceededBlocksCancellation(string $status): void
    {
        $this->expectSession($this->session('open', 'unpaid', 'pi_expiration'));
        $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', ['id' => 'pi_expiration', 'object' => 'payment_intent', 'status' => $status], 200, null];
        self::assertSame(0, $this->runCommand()); $this->assertOrder(0, 8);
    }

    private function webhook(array $session): void
    {
        $body = json_encode(['id' => 'evt_expiration', 'object' => 'event', 'type' => 'checkout.session.expired', 'data' => ['object' => $session]]);
        $time = time(); $signature = hash_hmac('sha256', $time.'.'.$body, $_SERVER['STRIPE_WEBHOOK_SECRET']);
        $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body);
    }

    public function testRepeatedSignedExpiredWebhookRestocksOnlyOnce(): void
    {
        $this->expectSession($this->session()); $this->webhook($this->session()); self::assertResponseIsSuccessful();
        $this->webhook($this->session()); self::assertResponseIsSuccessful(); $this->assertOrder(4, 10);
    }

    public function testExpiredWebhookCannotCancelPaidOrder(): void
    {
        $this->order->setState(1); $this->em->flush();
        $this->webhook($this->session()); self::assertResponseIsSuccessful(); $this->assertOrder(1, 8);
    }

    public function testStaleExpiredWebhookUsesCurrentStripeStatus(): void
    {
        $this->expectSession($this->session('complete', 'paid'));
        $this->webhook($this->session()); self::assertResponseIsSuccessful(); $this->assertOrder(1, 8);
    }

    public function testApiFailureDoesNotReleaseStockAndCommandFails(): void
    {
        $this->requests[] = ['get', '/v1/checkout/sessions/cs_expiration', ['error' => ['message' => 'Unavailable', 'type' => 'invalid_request_error']], 400, null];
        self::assertSame(1, $this->runCommand()); $this->assertOrder(0, 8);
    }

    public function testApiFailureMakesWebhookRetryable(): void
    {
        $this->requests[] = ['get', '/v1/checkout/sessions/cs_expiration', ['error' => ['message' => 'Unavailable', 'type' => 'invalid_request_error']], 400, null];
        $this->webhook($this->session()); self::assertResponseStatusCodeSame(503); $this->assertOrder(0, 8);
    }

    public function testNewCheckoutSessionExpiresAtOrderDeadline(): void
    {
        $this->order->setCreatedAt(new \DateTime('-1 hour'))->setStripeSessionId(null); $this->em->flush();
        $deadline = $this->order->getCreatedAt()->getTimestamp() + 86400;
        $this->requests[] = ['post', '/v1/checkout/sessions', ['id' => 'cs_new', 'object' => 'checkout.session', 'url' => 'https://checkout.example.test'], 200,
            static function ($params) use ($deadline) { self::assertSame($deadline, $params['expires_at']); }];
        self::assertSame('https://checkout.example.test', static::getContainer()->get(StripePayment::class)->checkout($this->order));
        self::assertSame('cs_new', $this->order->getStripeSessionId()); $this->assertOrder(0, 8);
    }

    public function testPaymentStartingDuringExpirationDoesNotReleaseStock(): void
    {
        $this->expectSession($this->session('open', 'unpaid', 'pi_expiration'));
        $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', ['id' => 'pi_expiration', 'object' => 'payment_intent', 'status' => 'requires_payment_method'], 200, null];
        $this->expectSession($this->session('expired', 'unpaid', 'pi_expiration'), 'post');
        $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', ['id' => 'pi_expiration', 'object' => 'payment_intent', 'status' => 'processing'], 200, null];
        self::assertSame(0, $this->runCommand()); $this->assertOrder(0, 8);
    }

    public function testExpiredWebhookDoesNotExpireAnOpenSession(): void
    {
        $this->expectSession($this->session('open'));
        $this->webhook($this->session()); self::assertResponseIsSuccessful(); $this->assertOrder(0, 8);
    }

    public function testInvalidWebhookSignatureDoesNotCallStripe(): void
    {
        $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE' => 'invalid'], '{}');
        self::assertResponseStatusCodeSame(400); $this->assertOrder(0, 8);
    }

    public function testNoStockReservationDoesNotCreateStock(): void
    {
        $this->order->setStockReserved(false); $this->em->flush();
        $this->expectSession($this->session()); self::assertSame(0, $this->runCommand()); $this->assertOrder(4, 8);
    }

    public function testUnrelatedSessionCannotCancelOrder(): void
    {
        $session = $this->session(); $session['client_reference_id'] = '99999';
        $this->expectSession($session); self::assertSame(1, $this->runCommand()); $this->assertOrder(0, 8);
    }

    public static function dryRunSessions(): iterable
    {
        yield 'expired' => ['expired', 'unpaid', 'session expirée → serait annulée'];
        yield 'open' => ['open', 'unpaid', 'session ouverte impayée → serait expirée'];
        yield 'paid' => ['complete', 'paid', 'déjà payée → serait synchronisée Payée'];
        yield 'processing' => ['complete', 'unpaid', 'paiement en traitement ou à vérifier → aucune action'];
    }

    /** @dataProvider dryRunSessions */
    public function testDryRunOnlyReadsStripeAndDoesNotFlush(string $status, string $payment, string $message): void
    {
        $this->expectSession($this->session($status, $payment, $payment === 'paid' ? 'pi_expiration' : null));
        // Une modification en attente ne doit pas être persistée implicitement par le dry-run.
        $this->product->setDescription('Modification non persistée');
        $tester = new CommandTester(new ExpireOrdersCommand(static::getContainer()->get(OrderRepository::class),
            static::getContainer()->get(StripePayment::class)));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Commande #'.$this->order->getId().' → '.$message, $tester->getDisplay());
        $this->assertOrder(0, 8);
        self::assertTrue($this->order->isStockReserved());
        self::assertNull($this->order->getStripePaymentIntentId());
        self::assertSame('Test', $this->em->getConnection()->fetchOne('SELECT description FROM product'));
        $this->em->clear(); // Ne pas laisser le changement de test être persisté ensuite.
    }

    public function testDryRunKeepsSameCandidateSelectionAndCanBeRepeated(): void
    {
        $this->order->setCreatedAt(new \DateTime('-23 hours')); $this->em->flush();
        $tester = new CommandTester(new ExpireOrdersCommand(static::getContainer()->get(OrderRepository::class),
            static::getContainer()->get(StripePayment::class)));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertSame('', $tester->getDisplay()); $this->assertOrder(0, 8);
        $this->order->setCreatedAt(new \DateTime('-25 hours'))->setStripeSessionId(null); $this->em->flush();
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(0, $tester->execute(['--dry-run' => true]));
            self::assertStringContainsString('Aucune session Stripe identifiable → aucune annulation automatique', $tester->getDisplay());
            self::assertStringContainsString('vérification manuelle nécessaire', $tester->getDisplay());
            $this->assertOrder(0, 8);
        }
    }

    public function testDryRunChecksPaymentIntentAndNeverExpiresProcessingSession(): void
    {
        $this->expectSession($this->session('open', 'unpaid', 'pi_expiration'));
        $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', ['id' => 'pi_expiration', 'object' => 'payment_intent', 'status' => 'processing'], 200, null];
        $tester = new CommandTester(new ExpireOrdersCommand(static::getContainer()->get(OrderRepository::class),
            static::getContainer()->get(StripePayment::class)));
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('aucune action', $tester->getDisplay()); $this->assertOrder(0, 8);
    }

    public static function missingSessions(): iterable
    {
        foreach ([null, '', '   '] as $session) {
            foreach ([null, 'pi_expiration'] as $intent) {
                foreach ([false, true] as $dryRun) { yield [$session, $intent, $dryRun]; }
            }
        }
    }

    /** @dataProvider missingSessions */
    public function testMissingSessionRequiresManualReview(?string $session, ?string $intent, bool $dryRun): void
    {
        $this->order->setStripeSessionId($session)->setStripePaymentIntentId($intent); $this->em->flush();
        $tester = new CommandTester(new ExpireOrdersCommand(static::getContainer()->get(OrderRepository::class),
            static::getContainer()->get(StripePayment::class)));
        self::assertSame(0, $tester->execute($dryRun ? ['--dry-run' => true] : []));
        self::assertStringContainsString('Aucune session Stripe identifiable → aucune annulation automatique', $tester->getDisplay());
        self::assertStringContainsString('vérification manuelle nécessaire', $tester->getDisplay());
        self::assertStringNotContainsString('serait annulée', $tester->getDisplay());
        $this->assertOrder(0, 8);
        self::assertTrue($this->order->isStockReserved());
        self::assertSame($intent, $this->order->getStripePaymentIntentId());
    }

    private function loginAdmin(): User
    {
        $admin = $this->order->getUser(); $admin->setRoles(['ROLE_ADMIN']); $this->em->flush();
        $this->client->loginUser($admin);
        return $admin;
    }

    public function testAdminHistoricalCancellationAndRepeatedClickAreAuditedAndIdempotent(): void
    {
        $this->order->setStripeSessionId(null); $admin = $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Annuler manuellement cette commande historique')->form(['confirmed' => '1']);
        self::assertStringContainsString('Cette commande ne peut pas être vérifiée automatiquement auprès de Stripe. Confirmez-vous son annulation manuelle ?', $form->getNode()->getAttribute('onsubmit'));
        $this->client->submit($form); self::assertResponseRedirects();
        $this->client->submit($form); self::assertResponseRedirects();
        $this->assertOrder(4, 10);
        $audit = $this->em->getRepository(\App\Entity\CancellationRequest::class)->findAll();
        self::assertCount(1, $audit);
        self::assertSame(\App\Service\HistoricalOrderCancellation::REASON, $audit[0]->getReason());
        self::assertSame('accepted', $audit[0]->getDecision());
        self::assertSame($admin->getId(), $audit[0]->getResolvedBy()->getId());
        self::assertNotNull($audit[0]->getResolvedAt());
    }

    public function testHistoricalCancellationOfExpiredIncoherentSessionRechecksStripe(): void
    {
        $this->loginAdmin();
        $session = $this->session(); $session['amount_total'] = 1;
        $this->expectSession($session);
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Annuler manuellement cette commande historique')->form(['confirmed' => '1']);
        $this->expectSession($session);
        $this->client->submit($form); self::assertResponseRedirects(); $this->assertOrder(4, 10);
    }

    public function testPaymentConfirmedAfterButtonDisplayPreventsHistoricalCancellation(): void
    {
        $this->loginAdmin();
        $session = $this->session(); $session['amount_total'] = 1;
        $this->expectSession($session);
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        $form = $crawler->selectButton('Annuler manuellement cette commande historique')->form(['confirmed' => '1']);
        $this->expectSession($this->session('complete', 'paid'));
        $this->client->submit($form); self::assertResponseRedirects(); $this->assertOrder(0, 8);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cancellation_request'));
    }

    public static function ineligibleHistoricalSessions(): iterable
    {
        yield 'normal expired session' => ['expired', 'unpaid', null, false];
        yield 'paid even incoherent' => ['complete', 'paid', null, true];
        yield 'still payable' => ['open', 'unpaid', null, true];
        yield 'completed processing' => ['complete', 'unpaid', null, true];
        yield 'processing intent' => ['expired', 'unpaid', 'processing', true];
        yield 'succeeded intent' => ['expired', 'unpaid', 'succeeded', true];
        yield 'authorized intent' => ['expired', 'unpaid', 'requires_capture', true];
    }

    /** @dataProvider ineligibleHistoricalSessions */
    public function testHistoricalActionIsHiddenForVerifiableOrProtectedPayments(string $status, string $payment, ?string $intentStatus, bool $incoherent): void
    {
        $this->loginAdmin();
        $session = $this->session($status, $payment, $intentStatus ? 'pi_expiration' : null);
        if ($incoherent) { $session['amount_total'] = 1; }
        $this->expectSession($session);
        if ($intentStatus && $status === 'expired') {
            $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', ['id' => 'pi_expiration', 'object' => 'payment_intent', 'status' => $intentStatus], 200, null];
        }
        $this->client->request('GET', '/admin/order/'.$this->order->getId()); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button:contains("Annuler manuellement cette commande historique")');
        $this->assertOrder(0, 8);
    }

    public function testHistoricalActionIsHiddenForRecentOrders(): void
    {
        $this->order->setCreatedAt(new \DateTime('-23 hours'))->setStripeSessionId(null); $this->loginAdmin();
        $this->client->request('GET', '/admin/order/'.$this->order->getId()); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button:contains("Annuler manuellement cette commande historique")'); $this->assertOrder(0, 8);
    }

    public function testHistoricalActionRequiresAdminCsrfAndExplicitConfirmation(): void
    {
        $url = '/admin/commande/'.$this->order->getId().'/annuler-historique';
        $this->client->loginUser($this->order->getUser());
        $this->client->request('POST', $url, ['confirmed' => '1']); self::assertResponseStatusCodeSame(403);
        $this->order->setStripeSessionId(null); $this->loginAdmin();
        $this->client->request('POST', $url, ['confirmed' => '1']); self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        $form = $crawler->selectButton('Annuler manuellement cette commande historique')->form();
        $this->client->submit($form); self::assertResponseStatusCodeSame(403); $this->assertOrder(0, 8);
    }

    public function testStripeApiErrorDoesNotEnableHistoricalAction(): void
    {
        $this->loginAdmin();
        $this->requests[] = ['get', '/v1/checkout/sessions/cs_expiration', ['error' => ['message' => 'Unavailable', 'type' => 'invalid_request_error']], 400, null];
        $this->client->request('GET', '/admin/order/'.$this->order->getId()); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button:contains("Annuler manuellement cette commande historique")'); $this->assertOrder(0, 8);
    }

    public function testHistoricalCancellationWithoutReservedStockDoesNotCreateStock(): void
    {
        $this->order->setStripeSessionId(null)->setStockReserved(false); $admin = $this->loginAdmin();
        static::getContainer()->get(\App\Service\HistoricalOrderCancellation::class)->cancel($this->order, $admin);
        $this->assertOrder(4, 8);
    }

    public function testMissingRemoteSessionAllowsExplicitHistoricalCancellation(): void
    {
        $this->loginAdmin();
        $error = ['error' => ['message' => 'No such session', 'type' => 'invalid_request_error', 'code' => 'resource_missing']];
        $this->requests[] = ['get', '/v1/checkout/sessions/cs_expiration', $error, 404, null];
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId()); self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Annuler manuellement cette commande historique')->form(['confirmed' => '1']);
        $this->requests[] = ['get', '/v1/checkout/sessions/cs_expiration', $error, 404, null];
        $this->client->submit($form); self::assertResponseRedirects(); $this->assertOrder(4, 10);
    }

    /** @dataProvider protectedStates */
    public function testHistoricalActionIsNeverEligibleForOtherStates(int $state): void
    {
        $this->order->setStripeSessionId(null)->setState($state); $this->em->flush();
        self::assertFalse(static::getContainer()->get(\App\Service\HistoricalOrderCancellation::class)->isEligible($this->order));
        $this->assertOrder($state, 8);
    }

    public function testHistoricalActionCannotIgnoreStoredPaymentIntentWithoutSession(): void
    {
        $this->order->setStripeSessionId(null)->setStripePaymentIntentId('pi_expiration'); $this->em->flush();
        self::assertFalse(static::getContainer()->get(\App\Service\HistoricalOrderCancellation::class)->isEligible($this->order));
        $this->assertOrder(0, 8);
    }

    public function testHistoricalPaidSessionWithoutReferenceLikeOrder77KeepsActionHidden(): void
    {
        $this->order->setCreatedAt(new \DateTime('2025-09-11')); $this->loginAdmin();
        $session = $this->session('complete', 'paid', 'pi_expiration');
        $session['client_reference_id'] = null;
        $this->expectSession($session);
        $this->client->request('GET', '/admin/order/'.$this->order->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button:contains("Vérifier le paiement Stripe")');
        self::assertSelectorNotExists('button:contains("Annuler manuellement cette commande historique")');
        $this->assertOrder(0, 8);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cancellation_request'));
    }

    private function historicalPayment(): array
    {
        $this->order->setCreatedAt(new \DateTime('2025-09-11'))->setStockReserved(false); $this->em->flush();
        $session = $this->session('complete', 'paid', 'pi_expiration'); $session['client_reference_id'] = null;
        return [$session, ['id' => 'pi_expiration', 'object' => 'payment_intent', 'status' => 'succeeded',
            'currency' => 'eur', 'amount_received' => $this->order->getTotalCents(), 'metadata' => []]];
    }

    public function testHistoricalAdminVerifyReconcilesWithoutChangingStockAndIsIdempotent(): void
    {
        [$session, $intent] = $this->historicalPayment(); $this->loginAdmin();
        $this->expectSession($session);
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Vérifier le paiement Stripe')->form();
        for ($i = 0; $i < 2; ++$i) {
            $this->expectSession($session);
            $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', $intent, 200, null];
            $this->client->submit($form); self::assertResponseRedirects(); $this->assertOrder(1, 8);
        }
        self::assertSame('pi_expiration', $this->em->getConnection()->fetchOne('SELECT stripe_payment_intent_id FROM `order`'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT stock_reserved FROM `order`'));
    }

    public static function historicalPaymentFailures(): iterable
    {
        yield 'session id' => ['session', 'id', 'cs_other'];
        yield 'session amount' => ['session', 'amount_total', 1];
        yield 'session currency' => ['session', 'currency', 'usd'];
        yield 'session not paid' => ['session', 'payment_status', 'unpaid'];
        yield 'session not complete' => ['session', 'status', 'open'];
        yield 'wrong reference is not missing' => ['session', 'client_reference_id', '999999'];
        yield 'intent not succeeded' => ['intent', 'status', 'processing'];
        yield 'intent id' => ['intent', 'id', 'pi_other'];
        yield 'intent currency' => ['intent', 'currency', 'usd'];
        yield 'received amount' => ['intent', 'amount_received', 1];
        yield 'session metadata' => ['session', 'metadata', ['order_id' => '999999']];
        yield 'intent metadata' => ['intent', 'metadata', ['order_reference' => 'OTHER']];
    }

    /** @dataProvider historicalPaymentFailures */
    public function testHistoricalPaymentMismatchNeverSynchronizes(string $source, string $field, mixed $value): void
    {
        [$session, $intent] = $this->historicalPayment();
        if ($source === 'session') { $session[$field] = $value; } else { $intent[$field] = $value; }
        $this->expectSession($session);
        if ($field !== 'id' || $source !== 'session') {
            if ($field !== 'client_reference_id') {
                $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', $intent, 200, null];
            }
        }
        try { static::getContainer()->get(StripePayment::class)->verify($this->order); self::fail('Réconciliation incohérente acceptée.'); }
        catch (\DomainException $e) { $this->assertOrder(0, 8); }
    }

    public function testRecentPaymentWithoutReferenceRetainsModernRules(): void
    {
        $session = $this->session('complete', 'paid', 'pi_expiration'); $session['client_reference_id'] = null;
        $this->expectSession($session);
        try { static::getContainer()->get(StripePayment::class)->verify($this->order); self::fail('Commande moderne sans référence acceptée.'); }
        catch (\DomainException $e) { $this->assertOrder(0, 8); }
    }

    public function testHistoricalStoredIntentConflictPreventsReconciliation(): void
    {
        [$session, $intent] = $this->historicalPayment();
        $this->order->setStripePaymentIntentId('pi_other'); $this->em->flush();
        $this->expectSession($session); $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', $intent, 200, null];
        try { static::getContainer()->get(StripePayment::class)->verify($this->order); self::fail('PaymentIntent local différent accepté.'); }
        catch (\DomainException $e) { $this->assertOrder(0, 8); }
    }

    public function testHistoricalMetadataMatchingIdentifiersAreAccepted(): void
    {
        [$session, $intent] = $this->historicalPayment();
        $session['metadata'] = ['order_id' => (string) $this->order->getId()];
        $intent['metadata'] = ['order_reference' => $this->order->getReference()];
        $this->expectSession($session); $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', $intent, 200, null];
        static::getContainer()->get(StripePayment::class)->verify($this->order); $this->assertOrder(1, 8);
    }

    public function testHistoricalSessionLinkedToSeveralOrdersCannotReconcile(): void
    {
        [$session, $intent] = $this->historicalPayment();
        $other = (new Order())->setUser($this->order->getUser())->setCreatedAt(new \DateTime('2025-09-11'))
            ->setState(0)->setCarrierName('Test')->setCarrierPrice(0)->setDelivery('Test')->setStripeSessionId('cs_expiration');
        $this->em->persist($other); $this->em->flush();
        $this->expectSession($session); $this->requests[] = ['get', '/v1/payment_intents/pi_expiration', $intent, 200, null];
        try { static::getContainer()->get(StripePayment::class)->verify($this->order); self::fail('Session partagée acceptée.'); }
        catch (\DomainException $e) {
            self::assertSame([0, 0], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT state FROM `order` ORDER BY id')));
            self::assertSame([], $this->requests);
        }
    }
}
