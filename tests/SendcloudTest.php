<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{Address, Order, Product, Shipment, User};
use App\Service\{OrderManager, SendcloudService, SendcloudShipping, SendcloudWebhook, ShippingConfiguration};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Réponses v3 simulées uniquement : aucun contact avec Sendcloud ou Stripe. */
final class SendcloudTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $user;
    private User $admin;
    private Address $address;
    private Product $product;
    private array $calls = [];
    private string $homePrice = '5.00';
    private bool $availablePoint = true;
    private bool $compatiblePoint = true;
    private bool $apiError = false;
    private bool $announceError = false;
    private ?array $remote = null;
    private array $extraOptions = [];
    private bool $multiCompatible = true;
    private bool $announcementPending = false;

    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new User())->setEmail('client@example.test')->setFirstname('Camille')->setLastname('Test')->setPassword('hash');
        $this->admin = (new User())->setEmail('admin@example.test')->setFirstname('Admin')->setLastname('Test')->setPassword('hash')->setRoles(['ROLE_ADMIN']);
        $this->address = (new Address())->setUser($this->user)->setFirstname('Camille')->setLastname('Test')->setAddress('10 rue de Test')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $this->product = (new Product())->setName('Rhum de test')->setSlug('rhum-sendcloud')->setDescription('Produit de test')->setIllustration('2025-08-30-851cfa871ccd52de3f20d860b2188b7f05abebe4.jpg')->setPrice(20)->setTva(20)->setStock(10)->setShippingWeightGrams(1200)->setIsHomepage(false);
        foreach ([$this->user, $this->admin, $this->address, $this->product] as $entity) { $this->em->persist($entity); } $this->em->flush();
        static::getContainer()->set(ShippingConfiguration::class, new ShippingConfiguration(true,
            ['name' => 'Expéditeur de test', 'address_line_1' => '1 rue de Test', 'postal_code' => '69001', 'city' => 'Lyon', 'country_code' => 'FR'],
            ['length_cm' => 30, 'width_cm' => 20, 'height_cm' => 15, 'packaging_weight_g' => 200, 'max_units' => 6], 20, false,
            $this->getName(false) !== 'testDisabledFallbackCannotBypassSendcloud'));
        $this->installApi($this->getName(false) !== 'testMultiParcelCreationRemainsDisabledBeforeAnyExternalCall'); $this->client->loginUser($this->user);
    }

    private function installApi(bool $allowLabels = true): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            $body = json_decode($options['body'] ?? '{}', true) ?? [];
            $path = parse_url($url, PHP_URL_PATH); $this->calls[] = compact('method', 'url', 'body');
            if ($this->apiError) { return new MockResponse('{"error":"private-api-details"}', ['http_code' => 503]); }
            if (str_ends_with($path, '/shipping-options')) {
                if (isset($body['to_service_point']) && !$this->compatiblePoint) { return $this->response(['data' => []]); }
                return $this->response(['data' => [$this->option('colissimo:home', 'colissimo', false, $this->homePrice),
                    $this->option('mondial_relay:relay', 'mondial_relay', true, '4.00'), ['code' => 'chronopost:unpriced', 'carrier' => ['code' => 'chronopost', 'name' => 'Chronopost'], 'quotes' => []], ...$this->extraOptions]]);
            }
            if (str_ends_with($path, '/check-availability')) { return $this->response(['data' => ['is_available' => $this->availablePoint]]); }
            if (preg_match('#/service-points/(\d+)$#', $path, $matches)) {
                $point = $this->point(); $point['id'] = (int) $matches[1];
                if ($point['id'] !== 42) { $point['carrier']['code'] = 'other_carrier'; }
                return $this->response(['data' => $point]);
            }
            if (str_ends_with($path, '/service-points')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                self::assertArrayHasKey('carrier_code', $query);
                self::assertArrayNotHasKey('use_integration_carriers', $query);
                return $this->response(['data' => ['results' => [$this->point()]]]);
            }
            if (str_ends_with($path, '/shipments/announce') || ($method === 'POST' && str_ends_with($path, '/shipments'))) {
                $this->remote = $body + ['id' => 'shipment-test'];
                $this->remote['parcels'] = array_map(fn ($parcel, $index) => $parcel + ['id' => 123 + $index, 'status' => ['code' => $this->announcementPending ? 'ANNOUNCING' : 'READY_TO_SEND'], 'tracking_number' => 'TRACK'.(123 + $index),
                    'tracking_url' => 'https://tracking.example.test/'.(123 + $index), 'documents' => $this->announcementPending ? [] : [['type' => 'label']]], $body['parcels'], array_keys($body['parcels']));
                if ($this->announceError) { return new MockResponse('', ['http_code' => 504]); }
                return $this->response(['data' => $this->remote], 201);
            }
            if (str_ends_with($path, '/shipments')) { return $this->response(['data' => $this->remote ? [$this->remote] : []]); }
            if (str_ends_with($path, '/shipments/shipment-test')) { return $this->response(['data' => $this->remote]); }
            if (str_ends_with($path, '/documents/label')) { return new MockResponse('%PDF-1.4 test'); }
            throw new \LogicException('Appel Sendcloud inattendu dans le test.');
        });
        static::getContainer()->set(SendcloudService::class, new SendcloudService($http, 'public-test', 'secret-test', new NullLogger(), $allowLabels));
    }
    private function response(array $body, int $status = 200): MockResponse { return new MockResponse(json_encode($body), ['http_code' => $status]); }
    private function option(string $code, string $carrier, bool $point, string $price): array
    {
        return ['code' => $code, 'name' => $point ? 'Livraison en relais' : 'Livraison à domicile', 'carrier' => ['code' => $carrier, 'name' => $carrier === 'colissimo' ? 'Colissimo' : 'Mondial Relay'],
            'functionalities' => ['multicollo' => $this->multiCompatible],
            'contract' => ['id' => 21], 'requirements' => ['is_service_point_required' => $point, 'fields' => [], 'export_documents' => false],
            'quotes' => [['price' => ['total' => ['value' => $price, 'currency' => 'EUR']], 'lead_time' => 48]]];
    }
    private function point(): array
    {
        return ['id' => 42, 'name' => 'Relais de test', 'carrier' => ['code' => 'mondial_relay', 'name' => 'Mondial Relay'],
            'carrier_service_point_id' => 'SHOP42', 'is_expired' => false,
            'address' => ['street' => 'Rue du Relais', 'house_number' => '2', 'postal_code' => '75001', 'city' => 'Paris', 'country_code' => 'FR']];
    }
    private function addCart(): void
    {
        $crawler = $this->client->request('GET', '/produit/rhum-sendcloud');
        $this->client->submit($crawler->selectButton('Ajouter au panier')->form());
    }
    private function addressStep(): string
    {
        $this->addCart(); $crawler = $this->client->request('GET', '/commande/sendcloud');
        $token = $crawler->filter('main input[name="_token"]')->attr('value');
        $this->export('sendcloud-adresse');
        $this->client->request('POST', '/commande/sendcloud', ['_token' => $token, 'action' => 'address', 'address' => $this->address->getId()]);
        $this->export('sendcloud-methodes');
        return $token;
    }
    private function select(string $token, bool $relay = false, ?int $point = null): void
    {
        $this->client->request('POST', '/commande/sendcloud', ['_token' => $token, 'address' => $this->address->getId(),
            'action' => $point ? 'confirm' : 'method', 'method' => hash('sha256', ($relay ? 'mondial_relay:relay' : 'colissimo:home').'|21'),
            'point' => $point, 'price' => '0.01', 'state' => '1']);
    }
    private function checkoutOrder(): Order
    {
        $this->select($this->addressStep()); self::assertResponseRedirects();
        return $this->em->getRepository(Order::class)->findOneBy([]);
    }
    private function paidOrder(): Order
    {
        $order = $this->checkoutOrder(); $order->setState(1)->setStripePaymentIntentId('pi_sendcloud_test'); $this->em->flush(); return $order;
    }
    private function shipping(): SendcloudShipping { return static::getContainer()->get(SendcloudShipping::class); }
    private function announcements(): int { return count(array_filter($this->calls, static fn ($call) => $call['method'] === 'POST'
        && (str_contains($call['url'], '/shipments/announce') || str_ends_with($call['url'], '/shipments')))); }

    private function packagingFixtures(): array
    {
        $boxes = [];
        foreach ([1, 2, 3, 6] as $capacity) {
            // Poids de test uniquement, jamais injectés en base locale/prod.
            $box = (new \App\Entity\Emballage())->setNom('Carton test '.$capacity)->setCapacite($capacity)->setPoidsVideGrammes(200)
                ->setLongueurCm(32)->setLargeurCm(22)->setHauteurCm(41)->setActif(true);
            $this->em->persist($box); $boxes[$capacity] = $box;
        }
        $this->product->setStock(40); $this->em->flush(); return $boxes;
    }

    public function testDevelopmentPackagingSeedAndTwoBottleCheckout(): void
    {
        $repository = $this->em->getRepository(\App\Entity\Emballage::class);
        $command = new \App\Command\EmballagesReferenceCommand($this->em, $repository, 'test');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['--development' => true]));
        self::assertSame(0, $tester->execute(['--development' => true]));
        self::assertCount(4, $repository->findAll());
        $service = static::getContainer()->get(\App\Service\Colisage::class);
        $expected = [1 => [[1], [1500]], 2 => [[2], [2900]], 3 => [[3], [4300]],
            4 => [[3, 1], [4300, 1500]], 5 => [[3, 2], [4300, 2900]],
            6 => [[6], [8400]], 7 => [[6, 1], [8400, 1500]]];
        foreach ($expected as $quantity => [$capacities, $weights]) {
            $plan = $service->plan([['object' => $this->product, 'qty' => $quantity]]);
            self::assertSame($capacities, array_column(array_column($plan, 'emballage'), 'capacite'));
            self::assertSame($weights, array_column($plan, 'poids_total_g'));
        }
        foreach ($repository->findAll() as $box) { self::assertTrue($box->isActif()); self::assertTrue($box->estComplet()); self::assertNull($box->getPoidsMaxGrammes()); }
        $production = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\EmballagesReferenceCommand($this->em, $repository, 'prod'));
        self::assertSame(1, $production->execute(['--development' => true]));
        $box = $repository->findOneBy(['capacite' => 2]);
        $box->setPoidsVideGrammes(550); $this->em->flush();
        self::assertSame(0, $tester->execute([])); self::assertSame(550, $box->getPoidsVideGrammes());
        $tester->execute(['--development' => true]);
        $this->packingCheckout(2);
        self::assertSelectorTextNotContains('main', 'Aucun emballage actif et complet');
        self::assertSame('2.900', $this->calls[0]['body']['parcels'][0]['weight']['value']);
        self::assertSame(['length' => '22', 'width' => '11.5', 'height' => '39.5', 'unit' => 'cm'], $this->calls[0]['body']['parcels'][0]['dimensions']);
        self::assertSame(0, $this->announcements());
    }

    private function packingCheckout(int $quantity): string
    {
        $crawler = $this->client->request('GET', '/produit/rhum-sendcloud');
        $this->client->submit($crawler->selectButton('Ajouter au panier')->form(['quantity' => $quantity]));
        $crawler = $this->client->request('GET', '/mon-panier');
        $this->client->click($crawler->selectLink('Poursuivre ma commande')->link());
        self::assertStringContainsString('/commande/sendcloud', $this->client->getRequest()->getUri());
        $token = $this->client->getCrawler()->filter('main input[name="_token"]')->attr('value');
        $this->client->request('POST', '/commande/sendcloud', ['_token' => $token, 'action' => 'address', 'address' => $this->address->getId()]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('input[name="method"]');
        self::assertSelectorTextNotContains('main', 'dépasse la capacité du colis');
        $this->export('checkout-colisage');
        return $token;
    }

    public static function packagingCheckouts(): iterable
    {
        foreach ([2 => [2], 3 => [3], 6 => [6], 7 => [6, 1], 12 => [6, 6]] as $quantity => $expected) {
            foreach (['standard', 'relay', 'express'] as $mode) { yield [$quantity, $expected, $mode]; }
        }
    }

    /** @dataProvider packagingCheckouts */
    public function testPackagingCheckoutAndFrozenOrder(int $quantity, array $expected, string $mode): void
    {
        $boxes = $this->packagingFixtures(); $this->homePrice = '9.25';
        $this->extraOptions = [$this->option('chronopost:express', 'chronopost', false, '13.07')];
        $token = $this->packingCheckout($quantity);
        $payload = $this->calls[0]['body']; self::assertCount(count($expected), $payload['parcels']);
        foreach ($expected as $index => $capacity) { self::assertSame(number_format(($capacity * 1200 + 200) / 1000, 3, '.', ''), $payload['parcels'][$index]['weight']['value']); }
        if (count($expected) > 1) { self::assertTrue($payload['functionalities']['multicollo']); }
        $code = match ($mode) { 'relay' => 'mondial_relay:relay', 'express' => 'chronopost:express', default => 'colissimo:home' };
        $parameters = ['_token' => $token, 'address' => $this->address->getId(), 'method' => hash('sha256', $code.'|21'), 'action' => 'method'];
        if ($mode === 'relay') {
            $this->client->request('POST', '/commande/sendcloud', $parameters); self::assertSelectorExists('[data-open-picker]');
            $parameters['point'] = 42; $parameters['action'] = 'confirm';
        }
        $this->client->request('POST', '/commande/sendcloud', $parameters); self::assertResponseRedirects();
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        self::assertSame(match ($mode) { 'relay' => 480, 'express' => 1568, default => 1110 }, (int) round($order->getCarrierPrice() * 100));
        self::assertCount(count($expected), $order->getColis());
        $snapshot = $order->getShippingSnapshot();
        self::assertSame($expected, array_column(array_column($snapshot['colisage'], 'emballage'), 'capacite'));
        self::assertSame(40 - $quantity, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
        $colisId = $order->getColis()->first()->getId(); $firstWeight = $order->getColis()->first()->getPoidsTotalGrammes();
        $this->em->find(\App\Entity\Emballage::class, $boxes[$expected[0]]->getId())->setNom('Carton modifié')->setPoidsVideGrammes(999)->setLongueurCm(99); $this->em->flush();
        $this->em->clear();
        $frozen = $this->em->find(\App\Entity\ColisCommande::class, $colisId);
        self::assertSame('Carton test '.$expected[0], $frozen->getNomEmballage()); self::assertSame($firstWeight, $frozen->getPoidsTotalGrammes());
        self::assertSame('32', $frozen->getDimensions()['length']);
        self::assertSame($snapshot, $this->em->getRepository(Order::class)->findOneBy([])->getShippingSnapshot());
        self::assertSame(0, $this->announcements());
    }

    public function testMultiParcelUnsupportedMethodsAreNotSold(): void
    {
        $this->packagingFixtures(); $this->multiCompatible = false;
        $delivery = static::getContainer()->get(\App\Service\SendcloudDelivery::class);
        self::assertSame([], $delivery->offers($this->address, [['object' => $this->product, 'qty' => 7]]));
        self::assertSame(0, $this->announcements());
    }

    public function testIncompletePackagingBlocksQuoteBeforeApiCall(): void
    {
        $box = (new \App\Entity\Emballage())->setNom('Carton non mesuré')->setCapacite(2)->setActif(true);
        $this->em->persist($box); $this->em->flush();
        $delivery = static::getContainer()->get(\App\Service\SendcloudDelivery::class);
        try { $delivery->offers($this->address, [['object' => $this->product, 'qty' => 2]]); self::fail('Emballage incomplet accepté.'); }
        catch (\DomainException) { self::assertCount(0, $this->calls); }
    }

    public function testPackagingChangeInvalidatesCheckoutQuote(): void
    {
        $boxes = $this->packagingFixtures(); $token = $this->packingCheckout(2);
        $this->em->find(\App\Entity\Emballage::class, $boxes[2]->getId())->setPoidsVideGrammes(300); $this->em->flush();
        $this->client->request('POST', '/commande/sendcloud', ['_token' => $token, 'address' => $this->address->getId(), 'action' => 'confirm', 'method' => hash('sha256', 'colissimo:home|21')]);
        self::assertResponseIsSuccessful(); self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
        self::assertSame('2.700', end($this->calls)['body']['parcels'][0]['weight']['value']);
    }

    public function testFutureMultiParcelAnnouncementUsesAsyncV3AndEachParcelTracking(): void
    {
        $this->packagingFixtures(); $token = $this->packingCheckout(7);
        $this->select($token);
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        $order->setState(1)->setStripePaymentIntentId('pi_mock_multicolis'); $this->em->flush();
        $shipment = $this->shipping()->create($order);
        self::assertSame(1, $this->announcements());
        self::assertStringEndsWith('/api/v3/shipments', end($this->calls)['url']);
        self::assertSame(['7.400', '1.400'], array_column(array_column(end($this->calls)['body']['parcels'], 'weight'), 'value'));
        self::assertSame([123, 124], $order->getColis()->map(fn ($p) => $p->getSendcloudParcelId())->toArray());
        self::assertSame(['TRACK123', 'TRACK124'], $order->getColis()->map(fn ($p) => $p->getNumeroSuivi())->toArray());
        $this->shipping()->create($order); self::assertSame(1, $this->announcements());
        self::assertSame('ready', $shipment->getState());
        self::assertSame([6, 1], array_map(fn ($p) => $p['parcel_items'][0]['quantity'], $this->remote['parcels']));
        $this->remote['parcels'][1]['status']['code'] = 'IN_TRANSIT';
        static::getContainer()->get(SendcloudWebhook::class)->handle(['action' => 'parcel_status_changed', 'parcel' => ['id' => 124]]);
        self::assertSame('IN_TRANSIT', $order->getColis()->get(1)->getStatut());
        $this->remote['parcels'][0]['status']['code'] = 'CANCELLED';
        $this->shipping()->synchronize($shipment); self::assertTrue($shipment->isActive());
        $this->remote['parcels'][1]['status']['code'] = 'CANCELLED';
        $this->shipping()->synchronize($shipment); self::assertFalse($shipment->isActive());
    }

    public function testMultiParcelCreationRemainsDisabledBeforeAnyExternalCall(): void
    {
        $this->packagingFixtures(); $token = $this->packingCheckout(7); $this->select($token);
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        $order->setState(1)->setStripePaymentIntentId('pi_mock_blocked'); $this->em->flush();
        try { $this->shipping()->create($order); self::fail('Création autorisée.'); }
        catch (\DomainException) { self::assertSame(0, $this->announcements()); }
        self::assertSame(0, $this->em->getRepository(Shipment::class)->count([]));
    }

    public function testAdministrationPackagingDraftCreationAndActivationValidation(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/emballage/new'); self::assertResponseIsSuccessful();
        $this->export('emballage-formulaire');
        $form = $crawler->filter('form[name="Emballage"]')->form(['Emballage[nom]' => 'Carton brouillon', 'Emballage[capacite]' => 2,
            'Emballage[longueurCm]' => '22', 'Emballage[largeurCm]' => '11.5', 'Emballage[hauteurCm]' => '39.5', 'Emballage[priorite]' => 0]);
        $this->client->submit($form); self::assertResponseRedirects();
        $box = $this->em->getRepository(\App\Entity\Emballage::class)->findOneBy(['nom' => 'Carton brouillon']);
        self::assertNull($box->getPoidsVideGrammes()); self::assertFalse($box->isActif());
        $crawler = $this->client->request('GET', '/admin/emballage/'.$box->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="Emballage"]')->form(['Emballage[actif]' => 1]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="Emballage"]', 'Complétez le poids réel');
        $crawler = $this->client->request('GET', '/admin/emballage/'.$box->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="Emballage"]')->form(['Emballage[actif]' => 1, 'Emballage[poidsVideGrammes]' => 250]));
        self::assertResponseRedirects();
        self::assertTrue($this->em->getRepository(\App\Entity\Emballage::class)->findOneBy(['nom' => 'Carton brouillon'])->isActif());
        $this->client->request('GET', '/admin/emballage'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Carton brouillon'); $this->export('emballages-liste');
    }

    public function testHistoricalPackagingCannotBeDeletedAndAdminShowsFrozenParcels(): void
    {
        $boxes = $this->packagingFixtures(); $token = $this->packingCheckout(7); $this->select($token);
        $order = $this->em->getRepository(Order::class)->findOneBy([]); $orderId = $order->getId();
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/emballage');
        $deleteToken = $crawler->filter('input[name="token"]')->attr('value');
        $this->client->request('POST', '/admin/emballage/'.$boxes[6]->getId().'/delete', ['token' => $deleteToken]);
        self::assertResponseRedirects();
        self::assertNotNull($this->em->find(\App\Entity\Emballage::class, $boxes[6]->getId()));
        $this->client->followRedirect(); self::assertSelectorTextContains('body', 'Désactivez-le au lieu de le supprimer');
        $this->client->request('GET', '/admin/order/'.$orderId); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#colis-title', 'Colis');
        self::assertSelectorTextContains('body', 'Carton test 6'); self::assertSelectorTextContains('body', '7,400 kg');
        $this->export('commande-multicolis');
    }

    public function testAsyncAnnouncementStaysPendingWithoutRetryAndCanBeSynchronized(): void
    {
        $this->packagingFixtures(); $token = $this->packingCheckout(7); $this->select($token);
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        $order->setState(1)->setStripePaymentIntentId('pi_mock_pending'); $this->em->flush(); $this->announcementPending = true;
        $shipment = $this->shipping()->create($order); self::assertSame('creating', $shipment->getState());
        self::assertFalse($shipment->hasLabel());
        $this->shipping()->create($order); self::assertSame(1, $this->announcements());
        foreach ($this->remote['parcels'] as &$parcel) { $parcel['status']['code'] = 'READY_TO_SEND'; $parcel['documents'] = [['type' => 'label']]; } unset($parcel);
        $this->shipping()->synchronize($shipment); self::assertSame('ready', $shipment->getState());
    }

    public function testMethodsAndServerPriceAreValidated(): void
    {
        $token = $this->addressStep(); self::assertSelectorTextContains('main', 'Colissimo'); self::assertSelectorTextContains('main', 'Mondial Relay');
        self::assertSelectorTextNotContains('main', 'Chronopost'); self::assertSelectorTextContains('main', '6,00 € TTC');
        $this->select($token); $order = $this->em->getRepository(Order::class)->findOneBy([]);
        self::assertSame(3000, $order->getTotalCents()); self::assertSame(0, $order->getState());
        self::assertSame(600, $order->getShippingSnapshot()['price_cents']); self::assertSame('1.400', $order->getShippingSnapshot()['parcels'][0]['weight']['value']);
        self::assertSame(0, $this->announcements()); self::assertCount(1, $order->getOrderDetails());
    }
    public function testUnknownMethodCannotCreateOrder(): void
    {
        $token = $this->addressStep(); $this->client->request('POST', '/commande/sendcloud', ['_token' => $token, 'address' => $this->address->getId(), 'action' => 'method', 'method' => 'forged']);
        self::assertSelectorTextContains('main', 'Choisissez une méthode'); self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }

    public function testCliAndCheckoutUseIdenticalQuotePayload(): void
    {
        $command = new \App\Command\SendcloudQuoteCommand(
            static::getContainer()->get(\App\Service\SendcloudDelivery::class),
            static::getContainer()->get(ShippingConfiguration::class),
            static::getContainer()->get(\App\Repository\AddressRepository::class),
            static::getContainer()->get(\App\Repository\ProductRepository::class));
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(0, $tester->execute(['--address' => $this->address->getId(), '--product' => $this->product->getId(), '--quantity' => 1]));
        $cliPayload = $this->calls[0]['body'];
        $this->addressStep();
        self::assertSame($cliPayload, $this->calls[1]['body']);
        self::assertSame('1.400', $cliPayload['parcels'][0]['weight']['value']);
        self::assertSame(['length' => '30', 'width' => '20', 'height' => '15', 'unit' => 'cm'], $cliPayload['parcels'][0]['dimensions']);
        self::assertSame(0, $this->announcements());
    }

    public function testFilteredCheckoutShowsCategoriesAndRejectsExcludedLetter(): void
    {
        $letter = $this->option('colissimo:letter', 'colissimo', false, '0.50');
        $letter['name'] = 'Unstamped Letter'; $letter['functionalities'] = ['form_factor' => 'letter'];
        $express = $this->option('chronopost:express', 'chronopost', false, '12.00');
        $express['carrier']['name'] = 'Chronopost'; $express['functionalities'] = ['form_factor' => 'parcel', 'premium' => true];
        $expensive = $this->option('colissimo:expensive', 'colissimo', false, '9.00');
        $expensive['name'] = 'Variante technique inutile';
        $this->extraOptions = [$letter, $express, $expensive];
        $token = $this->addressStep();
        foreach (['Livraison en point relais', 'Livraison standard à domicile', 'Livraison express', 'Chronopost'] as $label) {
            self::assertSelectorTextContains('main', $label);
        }
        self::assertSelectorTextNotContains('main', 'Unstamped Letter');
        self::assertSelectorTextNotContains('main', 'Variante technique inutile');
        self::assertSelectorCount(3, 'input[name="method"]');
        $this->client->request('POST', '/commande/sendcloud', ['_token' => $token, 'address' => $this->address->getId(),
            'action' => 'method', 'method' => hash('sha256', 'colissimo:letter|21')]);
        self::assertSelectorTextContains('main', 'Choisissez une méthode');
        self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
        self::assertSame(0, $this->announcements());
    }
    public function testOtherUsersAddressIsRejected(): void
    {
        $token = $this->addressStep(); $other = (new Address())->setUser($this->em->find(User::class, $this->admin->getId()))->setFirstname('Autre')->setLastname('Client')->setAddress('1 rue de Test')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678'); $this->em->persist($other); $this->em->flush();
        $this->client->request('POST', '/commande/sendcloud', ['_token' => $token, 'action' => 'address', 'address' => $other->getId()]); self::assertResponseStatusCodeSame(404);
    }
    public function testCheckoutRequiresCsrf(): void
    {
        $this->addCart(); $this->client->request('POST', '/commande/sendcloud', ['address' => $this->address->getId(), 'action' => 'address']);
        self::assertResponseStatusCodeSame(403); self::assertCount(0, $this->calls);
    }
    public function testValidRelayIsStoredAndRendered(): void
    {
        $token = $this->addressStep(); $this->select($token, true); self::assertSelectorTextContains('[data-open-picker]', 'Choisir mon point relais'); self::assertSelectorNotExists('input[type="radio"][name="point"]'); $this->export('sendcloud-relais');
        $this->select($token, true, 42); $order = $this->em->getRepository(Order::class)->findOneBy([]);
        self::assertSame(42, $order->getShippingSnapshot()['service_point']['id']); self::assertSame('75001', $order->getShippingSnapshot()['service_point']['address']['postal_code']);
        self::assertSame(2880, $order->getTotalCents()); $this->client->followRedirect(); self::assertSelectorTextContains('main', 'Relais de test');
    }
    public function testPickerSelectionIsValidatedWithoutCreatingOrderAndSurvivesRefresh(): void
    {
        $token = $this->addressStep(); $this->select($token, true);
        $parameters = ['_token' => $token, 'action' => 'point', 'address' => $this->address->getId(),
            'method' => hash('sha256', 'mondial_relay:relay|21'), 'point' => 42, 'post_number' => '12345678',
            'name' => 'FAUX NOM', 'country' => 'XX', 'price' => '0.01'];
        $this->client->request('POST', '/commande/sendcloud', $parameters);
        self::assertResponseIsSuccessful();
        $point = json_decode($this->client->getResponse()->getContent(), true)['point'];
        self::assertSame('Relais de test', $point['name']); self::assertSame('FR', $point['address']['country_code']);
        self::assertSame('12345678', $point['post_number']);
        self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
        self::assertSame(10, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
        $this->client->request('GET', '/commande/sendcloud');
        self::assertSelectorTextContains('[data-point-summary]', 'Relais de test');
        self::assertSelectorTextContains('[data-open-picker]', 'Changer de point relais');
        self::assertSelectorExists('input[name="point"][value="42"]');
        $this->export('sendcloud-relais-selectionne');
        $this->client->request('GET', '/mon-panier');
        $this->client->back();
        self::assertSelectorExists('input[name="point"][value="42"]');
        $this->client->request('POST', '/commande/sendcloud', array_replace($parameters, ['point' => 999]));
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/commande/sendcloud?reset=1');
        self::assertSelectorExists('input[type="radio"][name="address"]');
        self::assertFalse($this->client->getRequest()->getSession()->has('sendcloud_checkout'));
        self::assertSame(0, $this->announcements());
        $token = $this->addressStep();
        $this->client->request('POST', '/commande/sendcloud', array_replace($parameters, ['_token' => $token, 'action' => 'confirm']));
        self::assertResponseRedirects();
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        self::assertSame('12345678', $order->getShippingSnapshot()['service_point']['post_number']);
        self::assertSame('12345678', $order->getShippingSnapshot()['to_address']['po_box']);
        self::assertSame(0, $this->announcements());
    }

    public function testPickerRejectsIncompatibleUnavailableAndExpiredSelection(): void
    {
        $token = $this->addressStep(); $this->select($token, true);
        $parameters = ['_token' => $token, 'action' => 'point', 'address' => $this->address->getId(), 'method' => hash('sha256', 'mondial_relay:relay|21'), 'point' => 42];
        $this->compatiblePoint = false;
        $this->client->request('POST', '/commande/sendcloud', $parameters); self::assertResponseStatusCodeSame(422);
        $this->compatiblePoint = true; $this->availablePoint = false;
        $this->client->request('POST', '/commande/sendcloud', $parameters); self::assertResponseStatusCodeSame(422);
        $this->availablePoint = true;
        $this->client->request('POST', '/commande/sendcloud', $parameters + ['post_number' => '<script>']); self::assertResponseStatusCodeSame(422);
        $session = $this->client->getRequest()->getSession(); $saved = $session->get('sendcloud_checkout'); $saved['expires'] = 0; $session->set('sendcloud_checkout', $saved); $session->save();
        $this->client->request('POST', '/commande/sendcloud', $parameters); self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(Order::class)->count([])); self::assertSame(0, $this->announcements());
    }

    public function testWrongCarrierRelayIsRejected(): void
    {
        $token = $this->addressStep(); $this->select($token, true); $this->select($token, true, 999);
        self::assertSelectorTextContains('main', 'n’est pas valide'); self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }
    public function testUnavailableRelayIsRejected(): void
    {
        $token = $this->addressStep(); $this->select($token, true); $this->availablePoint = false; $this->select($token, true, 42);
        self::assertSelectorTextContains('main', 'n’est pas valide'); self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }
    public function testMethodCompatibilityIsRecheckedForRelay(): void
    {
        $token = $this->addressStep(); $this->select($token, true); $this->compatiblePoint = false; $this->select($token, true, 42);
        self::assertSelectorTextContains('main', 'n’est pas compatible'); self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }
    public function testChangedPriceRequiresNewConfirmation(): void
    {
        $token = $this->addressStep(); $this->homePrice = '7.00'; $this->select($token);
        self::assertSelectorTextContains('main', 'tarif de livraison a changé'); self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }
    public function testSnapshotPreservesAddressWeightAndProductPrice(): void
    {
        $order = $this->checkoutOrder(); $this->em->find(Address::class, $this->address->getId())->setAddress('Autre adresse'); $this->em->find(Product::class, $this->product->getId())->setPrice(999)->setShippingWeightGrams(9999); $this->em->flush();
        self::assertSame('Autre adresse', $this->em->getConnection()->fetchOne('SELECT address FROM address'));
        self::assertSame('10 rue de Test', $order->getShippingSnapshot()['to_address']['address_line_1']);
        self::assertSame('1.400', $order->getShippingSnapshot()['parcels'][0]['weight']['value']); self::assertSame(3000, $order->getTotalCents());
    }
    public function testDoubleCheckoutSubmissionDoesNotReserveStockTwice(): void
    {
        $token = $this->addressStep(); $this->select($token); $this->select($token);
        self::assertResponseRedirects(); self::assertSame(1, $this->em->getRepository(Order::class)->count([])); self::assertSame(9, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }
    public function testShipmentCreationIsUniqueAndUsesSnapshot(): void
    {
        $order = $this->paidOrder(); $shipment = $this->shipping()->create($order); $again = $this->shipping()->create($order);
        self::assertSame($shipment->getId(), $again->getId()); self::assertSame(1, $this->announcements()); self::assertSame('TRACK123', $shipment->getTrackingNumber());
        self::assertSame(1, $this->em->getRepository(Shipment::class)->count([])); self::assertSame('10 rue de Test', $this->remote['to_address']['address_line_1']);
        self::assertSame(1, $order->getState()); self::assertSame(9, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product'));
    }
    public function testUnpaidOrderCannotCreateShipment(): void
    {
        $order = $this->checkoutOrder(); $this->expectException(\DomainException::class);
        try { $this->shipping()->create($order); } finally { self::assertSame(0, $this->announcements()); }
    }
    public function testLabelCreationIsDisabledUnlessExplicitlyEnabled(): void
    {
        $order = $this->paidOrder();
        $blocked = new SendcloudShipping($this->em, new SendcloudService(new MockHttpClient(function () { self::fail('Aucun appel autorisé.'); }), 'public-test', 'secret-test', new NullLogger(), false), new NullLogger());
        $this->expectException(\DomainException::class);
        try { $blocked->create($order); } finally { self::assertSame(0, $this->announcements()); self::assertSame(0, $this->em->getRepository(Shipment::class)->count([])); }
    }
    public function testUncertainCreationIsNotRepeatedAndCanBeReconciled(): void
    {
        $order = $this->paidOrder(); $this->announceError = true;
        try { $this->shipping()->create($order); self::fail('Résultat incertain attendu.'); } catch (\DomainException $e) { self::assertStringContainsString('éviter un doublon', $e->getMessage()); }
        $shipment = $this->shipping()->create($order); self::assertSame('unknown', $shipment->getState()); self::assertSame(1, $this->announcements());
        $this->shipping()->synchronize($shipment); self::assertSame('ready', $shipment->getState()); self::assertSame(1, $this->announcements());
    }
    public function testAdminRightsAreRequiredForEveryShipmentAction(): void
    {
        $order = $this->paidOrder();
        foreach (['creer' => 'POST', 'verifier' => 'POST', 'etiquette' => 'GET'] as $action => $method) {
            $this->client->request($method, '/admin/commande/'.$order->getId().'/expedition/'.$action); self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(0, $this->announcements());
    }
    public function testAdminCreationRequiresCsrfAndSupportsLabelDownload(): void
    {
        $order = $this->paidOrder(); $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/commande/'.$order->getId().'/expedition/creer'); self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/admin/order/'.$order->getId());
        $this->client->submit($crawler->selectButton('Créer l’expédition')->form()); self::assertResponseRedirects(); self::assertSame(1, $this->announcements());
        $this->client->followRedirect(); $this->export('sendcloud-admin');
        $this->client->request('GET', '/admin/commande/'.$order->getId().'/expedition/etiquette'); self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF-', $this->client->getResponse()->getContent());
    }
    public function testOwnerSeesTrackingWithoutInternalIdentifiers(): void
    {
        $order = $this->paidOrder(); $this->shipping()->create($order);
        $this->client->request('GET', '/compte/commande/'.$order->getId()); self::assertSelectorTextContains('main', 'TRACK123');
        $this->export('sendcloud-suivi');
        self::assertSelectorExists('a[href="https://tracking.example.test/123"]'); self::assertSelectorTextNotContains('main', 'shipment-test');
        self::assertSelectorTextNotContains('main', 'colissimo:home');
        $this->client->request('GET', '/compte'); self::assertSelectorTextContains('main', 'TRACK123');
        $this->client->loginUser($this->admin); $this->client->request('GET', '/compte/commande/'.$order->getId()); self::assertResponseRedirects('/');
    }
    public function testApiFailureIsClearAndDoesNotCreateOrder(): void
    {
        $this->apiError = true; $this->addressStep(); self::assertSelectorTextContains('main', 'Sendcloud est indisponible');
        self::assertSelectorTextNotContains('main', 'private-api-details'); self::assertSelectorTextNotContains('main', 'secret-test');
        self::assertSame(0, $this->em->getRepository(Order::class)->count([])); self::assertSame(10, $this->product->getStock());
    }
    public function testWebhookSignatureAndOutOfOrderEventsUseApiState(): void
    {
        $order = $this->paidOrder(); $this->shipping()->create($order);
        $webhook = new SendcloudWebhook(true, 'signature-test', $this->em, $this->shipping()); static::getContainer()->set(SendcloudWebhook::class, $webhook);
        $body = json_encode(['action' => 'parcel_status_changed', 'parcel' => ['id' => 123, 'status' => ['code' => 'OLD_STATUS']]]);
        $this->client->request('POST', '/webhooks/sendcloud', [], [], ['HTTP_SENDCLOUD_SIGNATURE' => 'invalid'], $body); self::assertResponseStatusCodeSame(401);
        $this->remote['parcels'][0]['status']['code'] = 'DELIVERED';
        $headers = ['HTTP_SENDCLOUD_SIGNATURE' => hash_hmac('sha256', $body, 'signature-test')];
        $this->client->request('POST', '/webhooks/sendcloud', [], [], $headers, $body); self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', '/webhooks/sendcloud', [], [], $headers, $body); self::assertResponseStatusCodeSame(204);
        self::assertSame('DELIVERED', $this->em->getConnection()->fetchOne('SELECT status_code FROM shipment')); self::assertSame(1, $order->getState());
    }
    public function testWebhookIsDisabledByDefault(): void
    {
        $this->client->request('POST', '/webhooks/sendcloud', [], [], [], '{}'); self::assertResponseStatusCodeSame(404);
    }
    public function testMissingProductWeightFailsClosed(): void
    {
        $this->product->setShippingWeightGrams(null); $this->em->flush(); $this->addressStep();
        self::assertSelectorTextContains('main', 'poids d’un produit'); self::assertCount(0, $this->calls);
    }
    public function testActiveShipmentPreventsCancellationAndStockRelease(): void
    {
        $order = $this->paidOrder(); $this->shipping()->create($order);
        self::assertFalse($order->canRequestCancellation());
        $this->expectException(\DomainException::class);
        try { static::getContainer()->get(OrderManager::class)->cancelForAdmin($order); }
        finally { self::assertSame(9, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product')); }
    }
    public function testCancelledShipmentAllowsOneStockRestitution(): void
    {
        $order = $this->paidOrder(); $shipment = $this->shipping()->create($order);
        $this->remote['parcels'][0]['status']['code'] = 'CANCELLED'; $this->shipping()->synchronize($shipment);
        $manager = static::getContainer()->get(OrderManager::class); $manager->cancelForAdmin($order); $manager->cancelForAdmin($order);
        self::assertSame(10, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product')); self::assertSame(4, $order->getState());
        self::assertCount(1, $order->getOrderDetails());
    }
    public function testConfirmedRefundDoesNotRestockAnActiveShipment(): void
    {
        $order = $this->paidOrder(); $this->shipping()->create($order);
        $refund = (object) ['id' => 're_shipping_test', 'payment_intent' => 'pi_sendcloud_test', 'currency' => 'eur', 'amount' => $order->getTotalCents(), 'status' => 'succeeded'];
        $manager = static::getContainer()->get(OrderManager::class); $manager->synchronizeRefund($order, $refund); $manager->synchronizeRefund($order, $refund);
        self::assertSame(9, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product')); self::assertSame(6, $order->getState());
    }
    public function testNormalNavigationUsesSendcloudEvenAfterLegacyFallback(): void
    {
        $this->client->request('GET', '/commande/livraison?fallback=1');
        $crawler = $this->client->request('GET', '/');
        $crawler = $this->client->click($crawler->selectLink('Tous les produits')->link());
        $crawler = $this->client->click($crawler->selectLink('Rhum de test')->link());
        $this->client->submit($crawler->selectButton('Ajouter au panier')->form());
        $crawler = $this->client->followRedirect();
        $link = $crawler->selectLink('Poursuivre ma commande')->link();
        self::assertStringEndsWith('/commande/sendcloud', $link->getUri());
        $crawler = $this->client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSame('/commande/sendcloud', $this->client->getRequest()->getPathInfo());
        self::assertFalse($this->client->getRequest()->getSession()->has('shipping_legacy_fallback'));
        $crawler = $this->client->submit($crawler->selectButton('Voir les livraisons disponibles')->form(['address' => $this->address->getId()]));
        self::assertSelectorTextContains('main', 'Colissimo');
        $this->client->submit($crawler->selectButton('Vérifier ma commande')->form(['method' => hash('sha256', 'colissimo:home|21')]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('app_account_order', $this->client->getRequest()->attributes->get('_route'));
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        self::assertSame(0, $order->getState());
        self::assertSame(600, $order->getShippingSnapshot()['price_cents']);
        self::assertSame(0, $this->announcements());
    }

    public function testAddressSaveReturnsToSendcloudOrExplicitFallback(): void
    {
        $this->addCart();
        foreach ([false, true] as $legacy) {
            if ($legacy) { $this->client->request('GET', '/commande/livraison?fallback=1'); }
            $crawler = $this->client->request('GET', '/compte/adresse/ajouter');
            $this->client->submit($crawler->selectButton('Sauvegarder')->form([
                'address_user[firstname]' => 'Camille', 'address_user[lastname]' => 'Test',
                'address_user[address]' => '12 rue de Test', 'address_user[postal]' => '75001',
                'address_user[city]' => 'Paris', 'address_user[country]' => 'FR', 'address_user[phone]' => '0612345678',
            ]));
            self::assertResponseRedirects($legacy ? '/commande/livraison?fallback=1' : '/commande/sendcloud');
        }
    }

    public function testLegacyFallbackIsExplicitAndCanBeDisabled(): void
    {
        $this->client->request('GET', '/commande/livraison');
        self::assertResponseRedirects('/commande/sendcloud');
        $this->client->request('GET', '/commande/livraison?fallback=1');
        self::assertResponseRedirects('/mon-panier'); // Panier vide, après acceptation du fallback.
        self::assertTrue($this->client->getRequest()->getSession()->get('shipping_legacy_fallback'));
    }

    public function testDisabledFallbackCannotBypassSendcloud(): void
    {
        $this->client->request('GET', '/commande/livraison?fallback=1');
        self::assertResponseRedirects('/commande/sendcloud');
        $this->client->request('POST', '/commande/recapitulatif');
        self::assertResponseRedirects('/commande/sendcloud');
    }

    public function testApiTariffHasNoMargin(): void
    {
        self::assertSame(500, (new ShippingConfiguration(true, [], [], 20, true))->sellingPrice('5.00'));
        self::assertSame(600, (new ShippingConfiguration(true, [], [], 20, false))->sellingPrice('5.00'));
        self::assertSame(500, (new ShippingConfiguration(true, [], [], 0, false))->sellingPrice('5.00'));
    }

    public function testEnvironmentParcelValuesAreValidatedAndConverted(): void
    {
        $configuration = new ShippingConfiguration(true, [], ['length_cm' => '30', 'width_cm' => '20',
            'height_cm' => '15', 'packaging_weight_g' => '200', 'max_units' => '6'], '20', false);
        self::assertSame('1.400', $configuration->parcel([['object' => $this->product, 'qty' => 1]])['weight']['value']);
        self::assertSame(600, $configuration->sellingPrice('5.00'));
        $this->expectException(\DomainException::class);
        (new ShippingConfiguration(true, [], [], '', false))->sellingPrice('5.00');
    }

    private function export(string $name): void
    {
        $directory = getenv('SC_EXPORT_DIR'); if (!$directory) { return; }
        if (!is_dir($directory)) { mkdir($directory, 0777, true); }
        file_put_contents($directory.'/'.$name.'.html', $this->client->getResponse()->getContent());
    }
}
