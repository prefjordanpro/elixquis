<?php

namespace App\Tests;

use App\Entity\{Address, Carrier, Order, Product, User};
use App\Service\OrderManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Stripe\ApiRequestor;
use Stripe\HttpClient\{ClientInterface, CurlClient};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StripeRefundTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private Order $order;
    private Product $product;
    private User $admin;
    private array $requests = [];
    private ?string $originalKey;

    protected function setUp(): void
    {
        $this->originalKey = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_simulated_refunds_only';
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new User())->setEmail('admin@example.test')->setFirstname('Admin')->setLastname('Test')->setPassword('hash')->setRoles(['ROLE_ADMIN']);
        $this->product = (new Product())->setName('Rhum')->setSlug('rhum')->setDescription('Rhum')->setIllustration('rhum.jpg')->setPrice(20)->setTva(20)->setStock(5);
        $address = (new Address())->setUser($this->admin)->setFirstname('Admin')->setLastname('Test')->setAddress('10 rue de Paris')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $carrier = (new Carrier())->setName('Livraison')->setDescription('Standard')->setPrice(5)->setTva(20);
        foreach ([$this->admin, $this->product, $address, $carrier] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        $this->order = static::getContainer()->get(OrderManager::class)->create($this->admin, $address, $carrier, [['object' => $this->product, 'qty' => 2]]);
        $this->order->setState(1)->setStripeSessionId('cs_test_order')->setStripePaymentIntentId('pi_test_order');
        $this->em->flush(); $this->client->loginUser($this->admin);
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) {
            self::assertNotEmpty($this->requests, 'Tout appel Stripe doit être prévu et simulé.');
            [$expectedMethod, $path, $response, $code, $check] = array_shift($this->requests);
            self::assertSame($expectedMethod, $method); self::assertSame($path, parse_url($url, PHP_URL_PATH));
            if ($check) { $check($params, $headers); }
            if ($response instanceof \Throwable) { throw $response; }
            return [json_encode($response), $code, []];
        });
        ApiRequestor::setHttpClient($http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        if ($this->originalKey === null) { unset($_ENV['STRIPE_SECRET_KEY']); } else { $_ENV['STRIPE_SECRET_KEY'] = $this->originalKey; }
        parent::tearDown();
    }

    private function expectStripe(string $method, string $path, $response, int $code = 200, ?callable $check = null): void
    {
        $this->requests[] = [$method, $path, $response, $code, $check];
    }

    private function refundObject(string $status = 'succeeded', ?int $amount = null): array
    {
        return ['id' => 're_test_order', 'object' => 'refund', 'payment_intent' => 'pi_test_order', 'currency' => 'eur',
            'amount' => $amount ?? $this->order->getTotalCents(), 'status' => $status, 'metadata' => ['order_id' => (string) $this->order->getId()]];
    }

    private function expectPaymentAndRefund(string $status = 'succeeded', ?array $error = null): void
    {
        $this->expectStripe('get', '/v1/payment_intents/pi_test_order', ['id' => 'pi_test_order', 'object' => 'payment_intent', 'status' => 'succeeded', 'currency' => 'eur', 'amount_received' => $this->order->getTotalCents()]);
        $this->expectStripe('get', '/v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false], 200,
            static function ($params) { self::assertSame('pi_test_order', $params['payment_intent']); });
        $total = $this->order->getTotalCents(); $id = $this->order->getId();
        $this->expectStripe('post', '/v1/refunds', $error ?? $this->refundObject($status), $error ? 400 : 200,
            static function ($params, $headers) use ($total, $id) {
                self::assertSame($total, $params['amount']); self::assertSame('pi_test_order', $params['payment_intent']);
                self::assertSame((string) $id, $params['metadata']['order_id']);
                self::assertStringContainsString('Idempotency-Key: full-refund-order-'.$id.'-pi_test_order', implode("\n", $headers));
            });
    }

    private function form()
    {
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        return $crawler->selectButton('Rembourser la commande')->form();
    }

    private function assertStored(int $state, int $stock, ?string $status): void
    {
        $this->em->clear();
        $saved = $this->em->find(Order::class, $this->order->getId());
        self::assertSame($state, $saved->getState()); self::assertSame($status, $saved->getStripeRefundStatus());
        self::assertCount(1, $saved->getOrderDetails());
        self::assertSame(2, $saved->getOrderDetails()->first()->getProductQuantity());
        self::assertSame($this->order->getTotalCents(), $saved->getTotalCents());
        self::assertSame($stock, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
        self::assertSame([], $this->requests, 'Tous les appels Stripe prévus doivent avoir été exercés.');
    }

    /** @dataProvider refundableStates */
    public function testTotalRefundAndRepeatedSubmission(int $state, int $stock): void
    {
        if ($state === 4) { static::getContainer()->get(OrderManager::class)->cancelForAdmin($this->order); }
        else { $this->order->setState($state); $this->em->flush(); }
        $form = $this->form();
        self::assertStringContainsString('confirm(', $form->getNode()->getAttribute('onsubmit'));
        $this->expectPaymentAndRefund();
        // Ces paramètres ajoutés au POST ne sont jamais utilisés pour l'appel Stripe.
        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + ['amount' => 1, 'payment_intent' => 'pi_attacker']);
        self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->client->followRedirect(); self::assertSelectorTextContains('body', 'Remboursée');
        self::assertSelectorNotExists('button:contains("Rembourser la commande")');
        self::assertSelectorTextContains('body', 're_test_order');
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(6, $stock, 'succeeded');
    }

    public static function refundableStates(): array
    {
        return ['paid' => [1, 5], 'preparation' => [2, 5], 'cancelled' => [4, 5], 'shipped' => [3, 3], 'delivered' => [5, 3]];
    }

    public function testApiFailureKeepsStateAndStock(): void
    {
        $form = $this->form();
        $this->expectPaymentAndRefund('succeeded', ['error' => ['type' => 'invalid_request_error', 'message' => 'Refund rejected']]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->client->followRedirect(); self::assertSelectorTextContains('.alert-danger', 'Stripe n’a pas confirmé');
        $this->assertStored(1, 3, null);
    }

    /** @dataProvider incompleteRefundStatuses */
    public function testUnconfirmedRefundDoesNotChangeOrder(string $status): void
    {
        $form = $this->form(); $this->expectPaymentAndRefund($status);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->client->followRedirect();
        self::assertSelectorExists('button:contains("Synchroniser le remboursement")');
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject($status));
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(1, 3, $status);
    }

    public static function incompleteRefundStatuses(): array
    {
        return [['pending'], ['requires_action'], ['failed'], ['canceled']];
    }

    private function webhook(array $refund, string $type = 'refund.updated', bool $valid = true): void
    {
        $payload = json_encode(['id' => 'evt_refund_test', 'object' => 'event', 'type' => $type, 'data' => ['object' => $refund]]);
        $time = time(); $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_tests_uniquement');
        $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE' => $valid ? $signature : 'invalid'], $payload);
    }

    public function testPendingRefundConfirmedByWebhookOnceAndOldEventsUseCurrentStripeStatus(): void
    {
        static::getContainer()->get(OrderManager::class)->cancelForAdmin($this->order);
        $form = $this->form(); $this->expectPaymentAndRefund('pending'); $this->client->submit($form);
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject());
        $this->webhook($this->refundObject('pending')); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject());
        $this->webhook($this->refundObject('pending'), 'refund.created'); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStored(6, 5, 'succeeded');
    }

    public function testWebhookCanRecoverRefundWithoutLocalRefundId(): void
    {
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject());
        $this->webhook($this->refundObject(), 'refund.created'); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStored(6, 5, 'succeeded');
    }

    public function testWebhookSignatureAndPartialRefundCannotConfirmFullRefund(): void
    {
        $this->webhook($this->refundObject(), 'refund.updated', false);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject('succeeded', 1));
        $this->webhook($this->refundObject('succeeded', 1)); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStored(1, 3, null);
    }

    public function testWebhookRejectsUnrelatedPayment(): void
    {
        $refund = $this->refundObject(); $refund['payment_intent'] = 'pi_unrelated';
        $this->expectStripe('get', '/v1/refunds/re_test_order', $refund);
        $this->webhook($refund); self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertStored(1, 3, null);
    }

    public function testWebhookFailureAfterSuccessRestoresPreviousStateWithoutStockDuplication(): void
    {
        $form = $this->form(); $this->expectPaymentAndRefund(); $this->client->submit($form);
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject('failed'));
        $this->webhook($this->refundObject('failed'), 'refund.failed'); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStored(1, 5, 'failed');
    }

    public function testLegacyOrderResolvesPaymentFromStoredSession(): void
    {
        $this->order->setStripePaymentIntentId(null); $this->em->flush();
        $form = $this->form();
        $this->expectStripe('get', '/v1/checkout/sessions/cs_test_order', ['id' => 'cs_test_order', 'object' => 'checkout.session', 'payment_status' => 'paid',
            'currency' => 'eur', 'amount_total' => $this->order->getTotalCents(), 'client_reference_id' => (string) $this->order->getId(), 'payment_intent' => 'pi_test_order']);
        $this->expectPaymentAndRefund(); $this->client->submit($form);
        self::assertResponseRedirects('/admin/order/'.$this->order->getId()); $this->assertStored(6, 5, 'succeeded');
    }

    public function testExistingStripeRefundIsSynchronizedWithoutCreatingAnother(): void
    {
        $form = $this->form();
        $this->expectStripe('get', '/v1/payment_intents/pi_test_order', ['id' => 'pi_test_order', 'object' => 'payment_intent', 'status' => 'succeeded', 'currency' => 'eur', 'amount_received' => $this->order->getTotalCents()]);
        $this->expectStripe('get', '/v1/refunds', ['object' => 'list', 'data' => [$this->refundObject()], 'has_more' => false]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId()); $this->assertStored(6, 5, 'succeeded');
    }

    public function testLostStripeResponseIsRecoveredWithoutAnotherRefund(): void
    {
        $form = $this->form(); $this->expectPaymentAndRefund();
        $this->requests[2][2] = new \Stripe\Exception\ApiConnectionException('Simulated lost response');
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        $this->expectStripe('get', '/v1/payment_intents/pi_test_order', ['id' => 'pi_test_order', 'object' => 'payment_intent', 'status' => 'succeeded', 'currency' => 'eur', 'amount_received' => $this->order->getTotalCents()]);
        $this->expectStripe('get', '/v1/refunds', ['object' => 'list', 'data' => [$this->refundObject()], 'has_more' => false]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(6, 5, 'succeeded');
    }

    public function testExternalPartialRefundBlocksCreationOfFullRefund(): void
    {
        $form = $this->form();
        $this->expectStripe('get', '/v1/payment_intents/pi_test_order', ['id' => 'pi_test_order', 'object' => 'payment_intent', 'status' => 'succeeded', 'currency' => 'eur', 'amount_received' => $this->order->getTotalCents()]);
        $this->expectStripe('get', '/v1/refunds', ['object' => 'list', 'data' => [$this->refundObject('succeeded', 1)], 'has_more' => false]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(1, 3, null);
    }

    public function testStripeWebhookApiFailureRequestsRetryWithoutChangingOrder(): void
    {
        $this->expectStripe('get', '/v1/refunds/re_test_order', ['error' => ['type' => 'invalid_request_error', 'message' => 'Simulated API error']], 400);
        $this->webhook($this->refundObject()); self::assertSame(503, $this->client->getResponse()->getStatusCode());
        $this->assertStored(1, 3, null);
    }

    public function testConfirmedCheckoutStoresPaymentIntentAndLateEventDoesNotUndoRefund(): void
    {
        $this->order->setState(0)->setStripePaymentIntentId(null); $this->em->flush();
        $session = (object) ['id' => 'cs_test_order', 'payment_status' => 'paid', 'currency' => 'eur',
            'amount_total' => $this->order->getTotalCents(), 'client_reference_id' => (string) $this->order->getId(), 'payment_intent' => 'pi_test_order'];
        static::getContainer()->get(OrderManager::class)->confirmPayment($this->order, $session);
        self::assertSame('pi_test_order', $this->order->getStripePaymentIntentId());
        $form = $this->form(); $this->expectPaymentAndRefund(); $this->client->submit($form);
        $current = $this->em->find(Order::class, $this->order->getId());
        static::getContainer()->get(OrderManager::class)->confirmPayment($current, $session);
        $this->assertStored(6, 5, 'succeeded');
    }

    public function testCancelledUnpaidSessionCannotBeRefunded(): void
    {
        $form = $this->form();
        $current = $this->em->find(Order::class, $this->order->getId());
        $current->setState(4)->setStripePaymentIntentId(null); $this->em->flush();
        $this->expectStripe('get', '/v1/checkout/sessions/cs_test_order', ['id' => 'cs_test_order', 'object' => 'checkout.session', 'payment_status' => 'unpaid',
            'currency' => 'eur', 'amount_total' => $this->order->getTotalCents(), 'client_reference_id' => (string) $this->order->getId(), 'payment_intent' => null]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(4, 3, null);
    }

    public function testInvalidPaymentAmountCannotBeRefunded(): void
    {
        $form = $this->form();
        $this->expectStripe('get', '/v1/payment_intents/pi_test_order', ['id' => 'pi_test_order', 'object' => 'payment_intent', 'status' => 'succeeded', 'currency' => 'eur', 'amount_received' => 1]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId()); $this->assertStored(1, 3, null);
    }

    public function testRoleCsrfAndPostAreRequired(): void
    {
        $url = '/admin/commande/'.$this->order->getId().'/rembourser';
        $form = $this->form();
        $this->client->request('GET', $url); self::assertSame(405, $this->client->getResponse()->getStatusCode());
        foreach ([[], ['_token' => 'invalid']] as $params) {
            $this->client->request('POST', $url, $params); self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        $user = (new User())->setEmail('client@example.test')->setFirstname('Client')->setLastname('Test')->setPassword('hash');
        $this->em->persist($user); $this->em->flush(); $this->client->loginUser($user);
        $this->client->submit($form); self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertStored(1, 3, null);
    }

    public function testUnpaidOrderCannotBeRefundedEvenWithValidToken(): void
    {
        $form = $this->form(); $this->order->setState(0); $this->em->flush();
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(0, 3, null);
    }
}
