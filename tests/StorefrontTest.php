<?php

namespace App\Tests;

use App\Entity\{Address, Carrier, Product, User};
use App\Service\OrderManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class StorefrontTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $user;
    private Product $product;
    private Address $address;
    private Carrier $carrier;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new User())->setEmail('client@example.test')->setFirstname('Julie')->setLastname('Martin');
        $this->user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'MotDePasseSolide123!'));
        $this->product = (new Product())->setName('Rhum')->setSlug('rhum')->setDescription('Description')
            ->setIllustration('rhum.jpg')->setPrice(19.99)->setTva(20)->setStock(3);
        $this->address = (new Address())->setUser($this->user)->setFirstname('Julie')->setLastname('Martin')
            ->setAddress('<script>alert(1)</script>10 rue des Fleurs')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $this->carrier = (new Carrier())->setName('Livraison')->setDescription('Livraison standard')->setPrice(4.90)->setTva(20);
        foreach ([$this->user, $this->product, $this->address, $this->carrier] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
    }

    public function testPublicPagesAndLoginCsrf(): void
    {
        foreach (['/', '/catalogue', '/inscription', '/connexion', '/mon-panier', '/produit/rhum'] as $url) {
            $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        }
        $this->client->request('POST', '/connexion', ['_username' => $this->user->getEmail(), '_password' => 'MotDePasseSolide123!']);
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect(); self::assertSelectorExists('.alert-danger');
    }

    public function testAccessAndMutationProtection(): void
    {
        $this->client->request('GET', '/admin'); self::assertResponseRedirects('/connexion');
        $this->client->loginUser($this->user);
        foreach (['/admin', '/admin/product', '/admin/order/new', '/admin/facture/impression/1'] as $url) {
            $this->client->request('GET', $url); self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('GET', '/cart/add/'.$this->product->getId()); self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/cart/add/'.$this->product->getId()); self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/compte/adresses/'.$this->address->getId().'/supprimer'); self::assertResponseStatusCodeSame(403);
    }

    public function testCartFreshPriceAndStockLimit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $crawler = $this->client->request('GET', '/produit/rhum');
            $this->client->submit($crawler->selectButton('Ajouter au panier')->form());
        }
        $this->client->request('GET', '/mon-panier');
        self::assertSelectorTextContains('.text-bg-secondary', 'x3'); self::assertSelectorTextContains('body', '71,97 €');
        $this->em->find(Product::class, $this->product->getId())->setPrice(10); $this->em->flush();
        $this->client->request('GET', '/mon-panier'); self::assertSelectorTextContains('body', '36,00 €');
    }

    public function testCheckoutSnapshotsAndCancelIsIdempotent(): void
    {
        $orders = static::getContainer()->get(OrderManager::class);
        $order = $orders->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 2]]);
        self::assertSame(1, $this->product->getStock()); self::assertSame(5288, $order->getTotalCents());
        self::assertCount(1, $order->getOrderDetails());
        $this->product->setPrice(100); $this->em->flush(); self::assertSame(5288, $order->getTotalCents());
        $orders->cancelUnpaid($order); $orders->cancelUnpaid($order);
        self::assertSame(3, $this->product->getStock()); self::assertSame(4, $order->getState());
    }

    public function testStockFailureRollsBack(): void
    {
        try {
            static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 4]]);
            self::fail('Une commande dépassant le stock doit être refusée.');
        } catch (\DomainException $e) {
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order`'));
            self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
        }
    }

    public function testOrderOwnershipAndEscaping(): void
    {
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/compte/commande/'.$order->getId()); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('script:contains("alert(1)")');
        $other = (new User())->setEmail('autre@example.test')->setFirstname('Autre')->setLastname('Client')->setPassword('hash');
        $this->em->persist($other); $this->em->flush(); $this->client->loginUser($other);
        $this->client->request('GET', '/compte/commande/'.$order->getId()); self::assertResponseRedirects('/');
        $this->client->request('GET', '/compte/facture/impression/'.$order->getId()); self::assertResponseRedirects('/compte');
        $this->client->request('POST', '/commande/paiement/'.$order->getId()); self::assertResponseStatusCodeSame(404);
    }

    public function testUnpaidStripeCannotConfirmOrder(): void
    {
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        try {
            static::getContainer()->get(OrderManager::class)->confirmPayment($order, (object) ['payment_status' => 'unpaid']);
            self::fail('Un paiement non confirmé doit être refusé.');
        } catch (\DomainException $e) { self::assertSame(0, $order->getState()); }
    }

    public function testVerifiedPaymentAndTransitions(): void
    {
        $orders = static::getContainer()->get(OrderManager::class);
        $order = $orders->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $order->setStripeSessionId('cs_test_verified'); $this->em->flush();
        $session = (object) ['id' => 'cs_test_verified', 'payment_status' => 'paid', 'currency' => 'eur',
            'amount_total' => $order->getTotalCents(), 'client_reference_id' => (string) $order->getId()];
        $orders->confirmPayment($order, $session); $orders->confirmPayment($order, $session);
        self::assertSame(1, $order->getState()); self::assertSame(2, $this->product->getStock());
        $orders->advance($order, 1, 2); $orders->advance($order, 2, 3); $orders->advance($order, 3, 5);
        self::assertSame(5, $order->getState());
    }

    public function testCheckoutThroughFormsAndDuplicateSubmission(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/produit/rhum');
        $this->client->submit($crawler->selectButton('Ajouter au panier')->form());
        $crawler = $this->client->request('GET', '/commande/livraison');
        $form = $crawler->filter('form')->form(['order[addresses]' => $this->address->getId(), 'order[carriers]' => $this->carrier->getId()]);
        $this->client->submit($form);
        $order = $this->em->getRepository(\App\Entity\Order::class)->findOneBy(['user' => $this->user]);
        self::assertResponseRedirects('/compte/commande/'.$order->getId());
        self::assertSame(2, $this->em->find(Product::class, $this->product->getId())->getStock());
        $this->client->submit($form);
        self::assertResponseRedirects('/compte/commande/'.$order->getId());
        self::assertSame(1, $this->em->getRepository(\App\Entity\Order::class)->count([]));
    }

    public function testAgeConfirmationIsRequiredByServer(): void
    {
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/compte/commande/'.$order->getId());
        $form = $crawler->filter('form[action="/commande/paiement/'.$order->getId().'"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/compte/commande/'.$order->getId());
        self::assertNull($this->em->find(\App\Entity\Order::class, $order->getId())->getStripeSessionId());
    }

    public function testForgedAmountCannotConfirmPayment(): void
    {
        $orders = static::getContainer()->get(OrderManager::class);
        $order = $orders->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $order->setStripeSessionId('cs_test_forged'); $this->em->flush();
        try {
            $orders->confirmPayment($order, (object) ['id' => 'cs_test_forged', 'payment_status' => 'paid',
                'currency' => 'eur', 'amount_total' => 1, 'client_reference_id' => (string) $order->getId()]);
            self::fail('Un montant incorrect doit être refusé.');
        } catch (\DomainException $e) {
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order` WHERE id = ?', [$order->getId()]));
        }
    }

    public function testAdminOrderAndProductPages(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']); $this->em->flush();
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $this->client->loginUser($this->user);
        foreach (['/admin/order', '/admin/order/'.$order->getId(), '/admin/product', '/admin/product/'.$this->product->getId().'/edit'] as $url) {
            $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/admin/order/new'); self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/commande/'.$order->getId().'/statut', ['state' => 1]); self::assertResponseStatusCodeSame(403);
    }

    /** @dataProvider cancellableStates */
    public function testAdminCancellationRestoresStockOnceAndKeepsOrder(int $state): void
    {
        $this->user->setRoles(['ROLE_ADMIN']); $this->em->flush();
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 2]]);
        $order->setState($state);
        if ($state !== 0) { $order->setStripeSessionId('cs_test_no_network_allowed'); }
        $this->em->flush();
        $total = $order->getTotalCents();
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/admin/order/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $button = $crawler->selectButton('Annuler la commande');
        self::assertStringContainsString('btn-danger', $button->attr('class'));
        self::assertStringContainsString('confirm(', $button->ancestors()->filter('form')->attr('onsubmit'));
        $form = $button->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/order/'.$order->getId());
        $this->client->followRedirect();
        if ($state !== 0) {
            self::assertSelectorTextContains('body', 'effectuez le remboursement manuellement dans Stripe');
        }
        self::assertSelectorNotExists('button:contains("Annuler la commande")');
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/order/'.$order->getId());
        $this->em->clear();
        $saved = $this->em->find(\App\Entity\Order::class, $order->getId());
        self::assertSame(4, $saved->getState());
        self::assertFalse($saved->isStockReserved());
        self::assertSame($total, $saved->getTotalCents());
        self::assertCount(1, $saved->getOrderDetails());
        self::assertSame(2, $saved->getOrderDetails()->first()->getProductQuantity());
        self::assertSame(1, $this->em->getRepository(\App\Entity\Order::class)->count([]));
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }

    public static function cancellableStates(): array
    {
        return ['pending' => [0], 'paid' => [1], 'preparation' => [2]];
    }

    /** @dataProvider forbiddenCancellationStates */
    public function testAdminCannotCancelShippedOrDeliveredOrder(int $state): void
    {
        $this->user->setRoles(['ROLE_ADMIN']); $this->em->flush();
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/admin/order/'.$order->getId());
        $form = $crawler->selectButton('Annuler la commande')->form();
        $this->em->find(\App\Entity\Order::class, $order->getId())->setState($state); $this->em->flush();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/order/'.$order->getId());
        $this->client->followRedirect();
        self::assertSelectorNotExists('button:contains("Annuler la commande")');
        self::assertSelectorTextContains('body', 'Impossible d’annuler');
        self::assertSame($state, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }

    public static function forbiddenCancellationStates(): array
    {
        return ['shipped' => [3], 'delivered' => [5]];
    }

    public function testAdminCancellationRequiresRolePostAndCsrf(): void
    {
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $url = '/admin/commande/'.$order->getId().'/statut';
        $this->client->request('POST', $url, ['state' => 4]);
        self::assertResponseRedirects('/connexion');
        $this->client->loginUser($this->user);
        $this->client->request('POST', $url, ['state' => 4]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $admin = $this->em->find(User::class, $this->user->getId());
        $admin->setRoles(['ROLE_ADMIN']); $this->em->flush(); $this->client->loginUser($admin);
        $this->client->request('GET', $url);
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        foreach ([[], ['_token' => 'invalid']] as $parameters) {
            $this->client->request('POST', $url, $parameters + ['state' => 4]);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }

    public function testCancellationDoesNotRestoreUnreservedLegacyStock(): void
    {
        $orders = static::getContainer()->get(OrderManager::class);
        $order = $orders->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $order->setStockReserved(false)->setState(1); $this->em->flush();
        $orders->cancelForAdmin($order); $orders->cancelForAdmin($order);
        self::assertSame(4, $order->getState());
        self::assertSame(2, $this->product->getStock());
    }

    public function testWrongCurrentPasswordCannotChangePassword(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/compte/modifier-mot-de-passe');
        $this->client->submitForm('Mettre à jour mon mot de passe', ['password_user[actualPassword]' => 'incorrect',
            'password_user[plainPassword][first]' => 'NouveauMotDePasse123!', 'password_user[plainPassword][second]' => 'NouveauMotDePasse123!']);
        self::assertSelectorTextContains('body', 'Votre mot de passe actuel est incorrect.');
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid(
            $this->em->find(User::class, $this->user->getId()), 'MotDePasseSolide123!'));
    }

    public function testAdminCannotSaveStaleStockForm(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']); $this->em->flush(); $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/admin/product/'.$this->product->getId().'/edit');
        $form = $crawler->filter('form[name="Product"]')->form(['Product[stock]' => 10]);
        $this->em->find(Product::class, $this->product->getId())->setStock(2); $this->em->flush();
        $this->client->submit($form); self::assertResponseStatusCodeSame(409);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }

    public function testAdminCannotSaveNegativeStock(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']); $this->em->flush(); $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/admin/product/'.$this->product->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="Product"]')->form(['Product[stock]' => -1]));
        self::assertSelectorTextContains('body', 'Le stock ne peut pas être négatif.');
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }

    public function testProfileAndPdf(): void
    {
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/compte/informations');
        $this->client->submitForm('Enregistrer', ['profile[firstname]' => 'Marie', 'profile[lastname]' => 'Dupont']);
        self::assertResponseRedirects('/compte/informations');
        $this->client->request('GET', '/compte/facture/impression/'.$order->getId());
        self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', $this->client->getResponse()->getContent());
    }

    public function testSignedStripeWebhookIsIdempotent(): void
    {
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object' => $this->product, 'qty' => 1]]);
        $order->setStripeSessionId('cs_test_webhook'); $this->em->flush();
        $payload = json_encode(['id' => 'evt_test', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_webhook', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'currency' => 'eur', 'amount_total' => $order->getTotalCents(), 'client_reference_id' => (string) $order->getId()]]]);
        $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE' => 'invalide'], $payload);
        self::assertResponseStatusCodeSame(400);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_tests_uniquement');
        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', '/paiement/webhook', [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload);
            self::assertResponseIsSuccessful();
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }
}
