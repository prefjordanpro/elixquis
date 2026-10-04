<?php

namespace App\Tests;

use App\Entity\{Address, Carrier, Category, Order, Product, User};
use App\Service\{OrderCancellation, OrderManager};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Vérifie les parcours rendus sans toucher à la base locale ni appeler Stripe. */
final class UxPresentationTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $user;
    private Product $product;
    private Address $address;
    private Carrier $carrier;

    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new User())->setEmail('demo@example.test')->setFirstname('Camille')->setLastname('Exemple')->setPassword('hash')->setRoles(['ROLE_ADMIN']);
        $category = (new Category())->setName('Aux fruits')->setSlug('aux-fruits');
        $this->product = (new Product())->setName('Produit de démonstration UX')->setSlug('demo-ux')->setDescription('Description de test, sans caractéristique commerciale inventée.')
            ->setIllustration('2025-08-30-851cfa871ccd52de3f20d860b2188b7f05abebe4.jpg')->setPrice(20)->setTva(20)->setStock(5)->setCategory($category)->setIsHomepage(true);
        $this->address = (new Address())->setUser($this->user)->setFirstname('Camille')->setLastname('Exemple')->setAddress('10 rue des Exemples')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $this->carrier = (new Carrier())->setName('Transporteur de test')->setDescription('Description de test')->setPrice(5)->setTva(20);
        foreach ([$this->user, $category, $this->product, $this->address, $this->carrier] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
    }

    private function export(string $name): void
    {
        $directory = getenv('UX_EXPORT_DIR');
        if (!$directory) { return; }
        if (!is_dir($directory)) { mkdir($directory, 0777, true); }
        file_put_contents($directory.'/'.$name.'.html', $this->client->getResponse()->getContent());
    }

    public function testPublicPagesKeepSingleHeadingAndProtectedPurchase(): void
    {
        foreach (['/' => 'accueil', '/catalogue' => 'catalogue', '/categorie/aux-fruits' => 'categorie', '/produit/demo-ux' => 'produit', '/mon-panier' => 'panier-vide', '/connexion' => 'connexion', '/inscription' => 'inscription'] as $url => $name) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertCount(1, $crawler->filter('main h1'));
            self::assertSelectorExists('a.skip-link[href="#contenu"]');
            self::assertSelectorNotExists('script[src^="https://"]');
            $this->export($name);
        }
        $crawler = $this->client->request('GET', '/produit/demo-ux');
        self::assertSelectorNotExists('#homeCarousel');
        $form = $crawler->selectButton('Ajouter au panier')->form();
        self::assertNotEmpty($form['_token']->getValue());
        $this->client->submit($form); self::assertResponseRedirects('/mon-panier');
        $this->client->followRedirect(); self::assertSelectorTextContains('body', '24,00 €');
        $this->export('panier');
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }

    public function testEmptyCategoryOffersAUsableReturnToCatalogue(): void
    {
        $empty = (new Category())->setName('Catégorie de test vide')->setSlug('sans-produit');
        $this->em->persist($empty); $this->em->flush();
        $crawler = $this->client->request('GET', '/categorie/sans-produit');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('main h1'));
        self::assertSelectorTextContains('body', 'Aucun produit pour le moment');
        self::assertSelectorExists('main a[href="/catalogue"]');
        $this->export('categorie-vide');
    }

    public function testAccountCheckoutAndCancellationRemainAvailable(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/produit/demo-ux');
        $this->client->submit($crawler->selectButton('Ajouter au panier')->form());
        foreach (['/compte' => 'compte', '/compte/informations' => 'profil', '/compte/adresses' => 'adresses', '/compte/adresse/ajouter' => 'adresse-formulaire', '/compte/modifier-mot-de-passe' => 'mot-de-passe', '/commande/livraison' => 'checkout'] as $url => $name) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertCount(1, $crawler->filter('main h1'));
            $this->export($name);
        }
        self::assertSelectorTextContains('body', '24,00 €');
        self::assertSelectorTextContains('body', 'Transporteur de test');
        self::assertSelectorExists('input[name="order[_token]"]');
        $currentUser = $this->em->find(User::class, $this->user->getId());
        $order = static::getContainer()->get(OrderManager::class)->create($currentUser, $this->em->find(Address::class, $this->address->getId()),
            $this->em->find(Carrier::class, $this->carrier->getId()), [['object' => $this->em->find(Product::class, $this->product->getId()), 'qty' => 1]]);
        $crawler = $this->client->request('GET', '/compte/commande/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNotEmpty($crawler->selectButton('Demander l’annulation')->form()['_token']->getValue());
        self::assertSelectorExists('input[name="age_confirmed"][required]');
        $this->export('commande-validation');
        $saved = $this->em->find(Order::class, $order->getId());
        $saved->setStripeSessionId('cs_ux_confirmation'); $this->em->flush();
        $originalKey = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url) use ($order) {
            self::assertSame('get', $method);
            self::assertStringEndsWith('/checkout/sessions/cs_ux_confirmation', $url);
            return [json_encode(['id' => 'cs_ux_confirmation', 'object' => 'checkout.session', 'payment_status' => 'paid', 'currency' => 'eur',
                'amount_total' => $order->getTotalCents(), 'client_reference_id' => (string) $order->getId(), 'payment_intent' => 'pi_demo_ux']), 200, []];
        });
        try {
            $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_ux_simulated'; \Stripe\ApiRequestor::setHttpClient($http);
            $crawler = $this->client->request('GET', '/commande/merci/cs_ux_confirmation');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertCount(1, $crawler->filter('main h1'));
            self::assertSelectorTextContains('body', $order->getReference());
            $this->export('confirmation');
        } finally {
            \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
            if ($originalKey === null) { unset($_ENV['STRIPE_SECRET_KEY']); } else { $_ENV['STRIPE_SECRET_KEY'] = $originalKey; }
        }
        $saved = $this->em->find(Order::class, $order->getId());
        $saved->setState(1)->setStripePaymentIntentId('pi_demo_ux'); $this->em->flush();
        static::getContainer()->get(OrderCancellation::class)->request($saved, $currentUser, 'Motif de test pour la présentation.');
        $this->client->request('GET', '/compte/commande/'.$order->getId());
        self::assertSelectorTextContains('body', 'Votre demande est en cours de traitement.'); $this->export('commande-demande');
        $this->client->request('GET', '/compte'); $this->export('compte-commandes');
        $crawler = $this->client->request('GET', '/admin/order/'.$order->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNotEmpty($crawler->selectButton('Accepter et rembourser')->form()['_token']->getValue());
        self::assertNotEmpty($crawler->selectButton('Refuser la demande')->form()['_token']->getValue()); $this->export('administration');
        self::assertSame(7, (int) $this->em->getConnection()->fetchOne('SELECT state FROM `order`'));
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }
}
