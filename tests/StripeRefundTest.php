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

    private function cancellationForm()
    {
        $crawler = $this->client->request('GET', '/compte/commande/'.$this->order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        return $crawler->selectButton('Demander l’annulation')->form();
    }

    private function requestCancellation(string $reason = 'Changement de projet'): void
    {
        $form = $this->cancellationForm(); $form['reason'] = $reason;
        $this->client->submit($form); self::assertResponseRedirects('/compte/commande/'.$this->order->getId());
    }

    private function resolutionForm(bool $accept = true)
    {
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        return $crawler->selectButton($accept ? 'Accepter et rembourser' : 'Refuser la demande')->form();
    }

    /** @dataProvider clientCancellableStates */
    public function testOwnerCanRequestCancellationWithoutRefund(int $state): void
    {
        $this->order->setState($state);
        if ($state === 0) { $this->order->setStripeSessionId(null)->setStripePaymentIntentId(null); }
        $this->em->flush();
        $form = $this->cancellationForm(); $form['reason'] = '<script>alert(1)</script> Mon motif';
        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + ['state' => 6, 'amount' => 1, 'decision' => 'accepted']);
        self::assertResponseRedirects('/compte/commande/'.$this->order->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', $state === 0 ? 'Votre commande a été annulée.' : 'Votre demande d’annulation a bien été enregistrée.');
        if ($state !== 0) { self::assertSelectorTextContains('body', 'Votre demande est en cours de traitement.'); }
        self::assertSelectorNotExists('button:contains("Demander l’annulation")');
        $this->assertStored($state === 0 ? 4 : 7, $state === 0 ? 5 : 3, null);
        $saved = $this->em->find(Order::class, $this->order->getId());
        self::assertCount(1, $saved->getCancellationRequests());
        $request = $saved->getLatestCancellationRequest();
        self::assertSame($state, $request->getPreviousState());
        self::assertSame('<script>alert(1)</script> Mon motif', $request->getReason());
        self::assertSame($state === 0 ? 'accepted' : 'pending', $request->getDecision());
        self::assertNotNull($request->getRequestedAt());
        self::assertSame($state === 0, $request->getResolvedAt() !== null);
        if ($state !== 0) {
            $this->client->request('GET', '/admin/order'); self::assertSelectorTextContains('body', 'Demande d’annulation');
            $this->client->request('GET', '/admin/order/'.$this->order->getId());
            self::assertSelectorNotExists('script:contains("alert(1)")');
            self::assertSelectorTextContains('body', 'Mon motif');
        }
    }

    public static function clientCancellableStates(): array { return ['unpaid' => [0], 'paid' => [1], 'preparation' => [2]]; }

    public function testDoubleClientRequestDoesNotOverwriteHistoryOrRestoreStock(): void
    {
        $form = $this->cancellationForm(); $form['reason'] = 'Motif initial'; $this->client->submit($form);
        $requestedAt = $this->em->getConnection()->fetchOne('SELECT requested_at FROM cancellation_request');
        $form['reason'] = 'Motif modifié'; $this->client->submit($form);
        self::assertResponseRedirects('/compte/commande/'.$this->order->getId()); $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Votre demande est en cours de traitement.');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cancellation_request'));
        self::assertSame('Motif initial', $this->em->getConnection()->fetchOne('SELECT reason FROM cancellation_request'));
        self::assertSame($requestedAt, $this->em->getConnection()->fetchOne('SELECT requested_at FROM cancellation_request'));
        $this->assertStored(7, 3, null);
    }

    public function testAnotherClientCannotViewOrCancelOwnersOrder(): void
    {
        $form = $this->cancellationForm();
        $other = (new User())->setEmail('other@example.test')->setFirstname('Other')->setLastname('Client')->setPassword('hash');
        $this->em->persist($other); $this->em->flush(); $this->client->loginUser($other);
        $this->client->request('GET', '/compte/commande/'.$this->order->getId()); self::assertResponseRedirects('/');
        $this->client->submit($form); self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cancellation_request'));
        $this->assertStored(1, 3, null);
    }

    /** @dataProvider incompatibleClientStates */
    public function testIncompatibleClientStatusIsHiddenAndRejectedEvenWithValidToken(int $state): void
    {
        $form = $this->cancellationForm();
        $this->em->find(Order::class, $this->order->getId())->setState($state); $this->em->flush();
        $this->client->submit($form); self::assertResponseRedirects('/compte/commande/'.$this->order->getId());
        $this->client->followRedirect(); self::assertSelectorTextContains('body', 'Cette commande ne peut plus être annulée en ligne.');
        self::assertSelectorNotExists('button:contains("Demander l’annulation")');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cancellation_request'));
        $this->assertStored($state, 3, null);
    }

    public static function incompatibleClientStates(): array { return ['shipped' => [3], 'cancelled' => [4], 'delivered' => [5], 'refunded' => [6]]; }

    public function testClientCancellationRequiresPostCsrfAndAuthentication(): void
    {
        $url = '/compte/commande/'.$this->order->getId().'/annuler';
        $this->client->request('GET', $url); self::assertSame(405, $this->client->getResponse()->getStatusCode());
        foreach ([[], ['_token' => 'invalid']] as $parameters) {
            $this->client->request('POST', $url, $parameters); self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        $this->client->getCookieJar()->clear(); $this->client->request('POST', $url);
        self::assertResponseRedirects('/connexion');
        $this->assertStored(1, 3, null);
    }

    public function testReasonIsOptionalAndLengthIsCheckedByServer(): void
    {
        $form = $this->cancellationForm();
        $this->client->request('POST', $form->getUri(), ['_token' => $form['_token']->getValue(), 'reason' => str_repeat('a', 1001)]);
        self::assertResponseRedirects('/compte/commande/'.$this->order->getId());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cancellation_request'));
        $this->client->submit($form); self::assertResponseRedirects('/compte/commande/'.$this->order->getId());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT reason FROM cancellation_request'));
        $this->assertStored(7, 3, null);
    }

    public function testAdminAcceptanceRefundsOnceAndPreservesRequestHistory(): void
    {
        $this->requestCancellation(); $form = $this->resolutionForm(); $this->expectPaymentAndRefund();
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $resolvedAt = $this->em->getConnection()->fetchOne('SELECT resolved_at FROM cancellation_request');
        self::assertNotNull($resolvedAt);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(6, 5, 'succeeded');
        $request = $this->em->find(Order::class, $this->order->getId())->getLatestCancellationRequest();
        self::assertSame('accepted', $request->getDecision()); self::assertSame($this->admin->getId(), $request->getResolvedBy()->getId());
        self::assertSame('Changement de projet', $request->getReason());
        self::assertSame($resolvedAt, $this->em->getConnection()->fetchOne('SELECT resolved_at FROM cancellation_request'));
        $this->client->request('GET', '/compte/commande/'.$this->order->getId());
        self::assertSelectorTextContains('body', 'Votre commande a été annulée et remboursée.');
    }

    /** @dataProvider paidClientStates */
    public function testAdminRefusalRestoresPreviousStateWithoutRefundOrStockChange(int $state): void
    {
        $this->order->setState($state); $this->em->flush();
        $this->requestCancellation(); $form = $this->resolutionForm(false);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $resolvedAt = $this->em->getConnection()->fetchOne('SELECT resolved_at FROM cancellation_request');
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored($state, 3, null);
        $request = $this->em->find(Order::class, $this->order->getId())->getLatestCancellationRequest();
        self::assertSame('refused', $request->getDecision()); self::assertNotNull($request->getResolvedAt());
        self::assertSame($resolvedAt, $this->em->getConnection()->fetchOne('SELECT resolved_at FROM cancellation_request'));
        $this->client->request('GET', '/compte/commande/'.$this->order->getId());
        self::assertSelectorTextContains('body', 'Votre demande d’annulation a été refusée.');
    }

    public static function paidClientStates(): array { return [[1], [2]]; }

    public function testNewRequestAfterRefusalKeepsEarlierHistoryAndStaleResolutionIsHarmless(): void
    {
        $this->requestCancellation('Premier motif'); $oldForm = $this->resolutionForm(false); $this->client->submit($oldForm);
        $this->requestCancellation('Second motif'); $this->client->submit($oldForm);
        self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        self::assertSame(['refused', 'pending'], $this->em->getConnection()->fetchFirstColumn('SELECT decision FROM cancellation_request ORDER BY id'));
        self::assertSame(['Premier motif', 'Second motif'], $this->em->getConnection()->fetchFirstColumn('SELECT reason FROM cancellation_request ORDER BY id'));
        $this->assertStored(7, 3, null);
    }

    public function testAcceptedRequestKeepsCancellationOnStripeFailureAndCanRecover(): void
    {
        $this->requestCancellation(); $form = $this->resolutionForm();
        $this->expectPaymentAndRefund('succeeded', ['error' => ['type' => 'invalid_request_error', 'message' => 'Simulated failure']]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        self::assertSame('accepted', $this->em->getConnection()->fetchOne('SELECT decision FROM cancellation_request'));
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
        $this->client->request('GET', '/compte/commande/'.$this->order->getId());
        self::assertSelectorNotExists('.alert-success:contains("annulée et remboursée")');
        $refundForm = $this->form(); $this->expectPaymentAndRefund(); $this->client->submit($refundForm);
        self::assertResponseRedirects('/admin/order/'.$this->order->getId()); $this->assertStored(6, 5, 'succeeded');
    }

    public function testAcceptedPendingRefundIsConfirmedByWebhookWithoutStockDuplication(): void
    {
        $this->requestCancellation(); $form = $this->resolutionForm(); $this->expectPaymentAndRefund('pending'); $this->client->submit($form);
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject('pending'));
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject());
        $this->webhook($this->refundObject()); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStored(6, 5, 'succeeded');
    }

    public function testExternalRefundResolvesPendingClientRequest(): void
    {
        $this->requestCancellation(); $this->expectStripe('get', '/v1/refunds/re_test_order', $this->refundObject());
        $this->webhook($this->refundObject()); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('accepted', $this->em->getConnection()->fetchOne('SELECT decision FROM cancellation_request'));
        $this->assertStored(6, 5, 'succeeded');
    }

    public function testAdminResolutionRequiresRolePostAndDedicatedCsrfTokens(): void
    {
        $this->requestCancellation(); $accept = $this->resolutionForm(); $refuse = $this->resolutionForm(false);
        foreach ([$accept, $refuse] as $form) {
            $this->client->request('GET', $form->getUri()); self::assertSame(405, $this->client->getResponse()->getStatusCode());
            foreach ([[], ['_token' => 'invalid']] as $params) {
                $this->client->request('POST', $form->getUri(), $params); self::assertSame(403, $this->client->getResponse()->getStatusCode());
            }
        }
        $this->client->request('POST', $accept->getUri(), $refuse->getPhpValues()); self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $user = (new User())->setEmail('not-admin@example.test')->setFirstname('Client')->setLastname('Test')->setPassword('hash');
        $this->em->persist($user); $this->em->flush(); $this->client->loginUser($user);
        foreach ([$accept, $refuse] as $form) { $this->client->submit($form); self::assertSame(403, $this->client->getResponse()->getStatusCode()); }
        $this->assertStored(7, 3, null);
    }

    public function testOrdinaryOwnerCanRequestButCannotResolveCancellation(): void
    {
        $this->admin->setRoles([]); $this->em->flush(); $this->client->loginUser($this->admin);
        $this->requestCancellation();
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM cancellation_request');
        foreach (['accepter', 'refuser'] as $action) {
            $this->client->request('POST', '/admin/commande/'.$this->order->getId().'/demande-annulation/'.$id.'/'.$action, ['state' => 6]);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        $this->assertStored(7, 3, null);
    }

    public function testUnpaidCancellationExpiresStripeSessionAndRestoresStockOnce(): void
    {
        $this->order->setState(0)->setStripePaymentIntentId(null); $this->em->flush();
        $form = $this->cancellationForm();
        $this->expectStripe('get', '/v1/checkout/sessions/cs_test_order', ['id' => 'cs_test_order', 'object' => 'checkout.session', 'status' => 'open']);
        $this->expectStripe('post', '/v1/checkout/sessions/cs_test_order/expire', ['id' => 'cs_test_order', 'object' => 'checkout.session', 'status' => 'expired']);
        $this->client->submit($form); self::assertResponseRedirects('/compte/commande/'.$this->order->getId());
        $this->client->submit($form); self::assertResponseRedirects('/compte/commande/'.$this->order->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cancellation_request'));
        $this->assertStored(4, 5, null);
    }

    public function testAdminCannotResolveARequestAttachedToAnotherOrder(): void
    {
        $this->requestCancellation(); $form = $this->resolutionForm();
        $address = $this->em->getRepository(Address::class)->findOneBy([]);
        $carrier = $this->em->getRepository(Carrier::class)->findOneBy([]);
        $other = static::getContainer()->get(OrderManager::class)->create($this->em->find(User::class, $this->admin->getId()), $address, $carrier,
            [['object' => $this->em->find(Product::class, $this->product->getId()), 'qty' => 1]]);
        $other->setState(1); $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE cancellation_request SET order_id = ?', [$other->getId()]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(7, 2, null);
        self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT decision FROM cancellation_request'));
    }

    public function testLatePaymentConfirmationDoesNotErasePendingRequest(): void
    {
        $this->requestCancellation();
        $current = $this->em->find(Order::class, $this->order->getId());
        static::getContainer()->get(OrderManager::class)->confirmPayment($current, (object) [
            'id' => 'cs_test_order', 'payment_status' => 'paid', 'currency' => 'eur', 'amount_total' => $this->order->getTotalCents(),
            'client_reference_id' => (string) $this->order->getId(), 'payment_intent' => 'pi_test_order',
        ]);
        self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT decision FROM cancellation_request'));
        $this->assertStored(7, 3, null);
    }

    public function testPendingRequestCannotBeShippedThroughForgedStatePost(): void
    {
        $crawler = $this->client->request('GET', '/admin/order/'.$this->order->getId());
        $form = $crawler->selectButton('Passer en préparation')->form();
        $this->requestCancellation();
        $parameters = $form->getPhpValues(); $parameters['state'] = 3;
        $this->client->request('POST', $form->getUri(), $parameters);
        self::assertResponseRedirects('/admin/order/'.$this->order->getId()); $this->assertStored(7, 3, null);
    }

    public function testUnpaidOrderCannotBeRefundedEvenWithValidToken(): void
    {
        $form = $this->form(); $this->order->setState(0); $this->em->flush();
        $this->client->submit($form); self::assertResponseRedirects('/admin/order/'.$this->order->getId());
        $this->assertStored(0, 3, null);
    }
}
