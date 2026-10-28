<?php
namespace App\Tests;

use App\Entity\{Address, Category, CommercialSettings, Emballage, Order, OrderDetail, Product, PromoCode, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BackofficeWorkspaceTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $admin;
    private array $products = [];
    private array $files = [];
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new User())->setEmail('paul@example.test')->setFirstname('Paul')->setLastname('Elixquis')->setPassword('unused')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($this->admin);
        $category = (new Category())->setName('Recettes fruitées'); $this->em->persist($category);
        foreach (['Rupture' => 0, 'Faible' => 3, 'Normal' => 6] as $label => $stock) {
            $p = (new Product())->setName('Recette '.$label)->setCategory($category)->setDescription('Fruits et épices')->setPrice(20)->setTva(20)->setStock($stock)->setIllustration('2025-08-30-851cfa871ccd52de3f20d860b2188b7f05abebe4.jpg');
            $this->em->persist($p); $this->products[$label] = $p;
        }
        $this->products['Normal']->setPromotionPercent(15)->setDescription('<div>Recette <strong>artisanale</strong> aux fruits.</div>');
        $this->em->persist((new PromoCode())->setCode('BIENVENUE')->setName('Première visite')->setActive(true));
        foreach ([0, 1, 2, 3] as $state) {
            $order = (new Order())->setCreatedAt(new \DateTime())->setUser($this->admin)->setState($state)->setCarrierName('Domicile')->setCarrierPrice(5)->setDelivery("Paul Elixquis\n1 rue des Saveurs\n75002 Paris");
            $order->addOrderDetail((new OrderDetail())->setProduct($this->products['Normal'])->setProductName('Recette fruits et épices de Madagascar')->setProductIllustration($this->products['Normal']->getIllustration())->setProductQuantity(3)->setProductPrice(24)->setProductTva(20));
            $this->em->persist($order);
        }
        $this->em->flush(); $this->client->loginUser($this->admin);
    }
    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
    }
    private function export(string $name): void
    {
        if ($dir = getenv('BO_REVIEW_DIR')) { if (!is_dir($dir)) mkdir($dir, 0777, true); file_put_contents($dir.'/'.$name.'.html', $this->client->getResponse()->getContent()); }
    }
    public function testDashboardHasUsefulCountsAndCanonicalNavigation(): void
    {
        $crawler = $this->client->request('GET', '/admin'); self::assertResponseIsSuccessful();
        self::assertCount(7, $crawler->filter('.workspace-metric'));
        self::assertSame('2', $crawler->filter('.workspace-metric strong')->first()->text());
        self::assertSelectorExists('a[href="/admin/order?traitement=a-preparer"]');
        self::assertSelectorTextNotContains('#main-menu', 'Bannières'); self::assertSelectorTextNotContains('#main-menu', 'Utilisateurs');
        self::assertSelectorTextContains('#main-menu', 'Clients'); self::assertSelectorTextContains('#main-menu', 'Modifier le site');
        self::assertSame(1, $crawler->filter('#main-menu')->selectLink('Produits')->count());
        $this->export('dashboard');
        $this->client->request('GET', '/admin/order?traitement=a-preparer'); self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('.datagrid tbody tr[data-id]'));
        self::assertSelectorTextNotContains('.datagrid tbody', 'Expédiée');
        $this->export('orders');
    }
    public function testStockFiltersAndPromotionLinksUseTheExistingProductEditor(): void
    {
        foreach (['rupture'=>'Rupture', 'faible'=>'Faible', 'normal'=>'Normal'] as $level=>$name) {
            $crawler = $this->client->request('GET', '/admin/stock?niveau='.$level); self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('.workspace-product-row'));
            self::assertSelectorTextContains('.workspace-product-row', 'Recette '.$name);
        }
        $this->client->request('GET', '/admin/stock'); $this->export('stock');
        $this->client->request('GET', '/admin/promotions-produits?selection=configurees'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.workspace-product-row', '−15 %');
        self::assertSelectorExists('a[href="/admin/product/'.$this->products['Normal']->getId().'/edit#Product_promotionSelection"]');
        $this->export('promotions');
    }
    public function testProductCreationHasNoTechnicalFieldsAndKeepsPricesAndImagesSafe(): void
    {
        $crawler = $this->client->request('GET', '/admin/product/new'); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="Product[slug]"]');
        self::assertSelectorTextContains('form[name="Product"]', 'Prix hors taxes');
        $this->export('product-new');
        $temp = tempnam(sys_get_temp_dir(), 'paul-product'); $this->files[] = $temp;
        copy(dirname(__DIR__).'/public/images/editorial/degustation-ambree-v2.jpg', $temp);
        $form = $crawler->filter('form[name="Product"]')->form([
            'Product[name]'=>'Nouvelle recette de Paul', 'Product[description]'=>'Une nouvelle recette',
            'Product[price]'=>'20', 'Product[tva]'=>'20', 'Product[stock]'=>'12', 'Product[promotionSelection]'=>'15',
        ]);
        $form['Product[illustration][file]']->upload($temp, 'photo.jpg');
        $this->client->submit($form); self::assertResponseRedirects();
        $p = $this->em->getRepository(Product::class)->findOneBy(['name'=>'Nouvelle recette de Paul']);
        self::assertNotNull($p); self::assertSame('nouvelle-recette-de-paul', $p->getSlug());
        self::assertSame(12, $p->getStock()); self::assertSame(20.4, $p->getPriceWt());
        $path = dirname(__DIR__).'/public/uploads/'.$p->getIllustration(); $this->files[] = $path; self::assertFileExists($path);
        $crawler = $this->client->request('GET', '/admin/product/'.$p->getId().'/edit');
        self::assertSelectorExists('img.content-image-preview'); $this->export('product-edit');
        $form = $crawler->filter('form[name="Product"]')->form(['Product[name]'=>'Nom amélioré', 'Product[stock]'=>'9']);
        $this->client->submit($form); self::assertResponseRedirects();
        $this->em->clear(); $fresh = $this->em->find(Product::class, $p->getId());
        self::assertSame('nouvelle-recette-de-paul', $fresh->getSlug()); self::assertSame(9, $fresh->getStock());
    }
    public function testSiteHasOneEntryPointAndCarouselIsInsideHomepageEditing(): void
    {
        $this->client->request('GET', '/admin/modifier-le-site'); self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/accueil-du-site"]'); self::assertSelectorTextContains('.workspace-panel', 'Images du carrousel');
        $this->export('site');
        $this->client->request('GET', '/admin/accueil-du-site'); $this->client->followRedirect(); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.content-body', 'Grande image d’accueil');
        self::assertSelectorTextNotContains('.content-body', 'Hero');
        self::assertSelectorExists('.page-actions a[href="/admin/header"]'); $this->export('home-editor');
        $this->client->request('GET', '/admin/header/new'); self::assertResponseIsSuccessful(); $this->export('carousel');
    }
    public function testExistingRichDescriptionRemainsEditableWithoutLosingStoredContent(): void
    {
        $p = $this->products['Normal']; $original = $p->getDescription();
        $crawler = $this->client->request('GET', '/admin/product/'.$p->getId().'/edit'); self::assertResponseIsSuccessful();
        self::assertSelectorExists('trix-editor');
        self::assertSame($original, $crawler->filter('textarea[name="Product[description]"]')->text());
        $this->export('product-existing');
        $form = $crawler->filter('form[name="Product"]')->form(['Product[stock]'=>'8']);
        $this->client->submit($form); self::assertResponseRedirects();
        $this->em->clear(); $fresh = $this->em->find(Product::class, $p->getId());
        self::assertSame($original, $fresh->getDescription()); self::assertSame(8, $fresh->getStock());
    }
    public function testFreeShippingAndStockSettingsAreSeparateViewsOfSameSettings(): void
    {
        $this->client->request('GET', '/admin/livraison-offerte'); $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('input[name="CommercialSettings[lowStockThreshold]"]');
        $this->export('shipping');
        $form = $crawler->filter('form[name="CommercialSettings"]')->form(['CommercialSettings[freeShippingMode]'=>'threshold', 'CommercialSettings[freeShippingThresholdCents]'=>'80']);
        $this->client->submit($form); self::assertResponseRedirects();
        $this->client->request('GET', '/admin/regler-le-stock'); $crawler = $this->client->followRedirect();
        self::assertSelectorNotExists('[data-commercial-shipping-mode]'); $this->export('stock-settings');
        $form = $crawler->filter('form[name="CommercialSettings"]')->form(['CommercialSettings[lowStockThreshold]'=>'8']);
        $this->client->submit($form); self::assertResponseRedirects();
        $this->em->clear(); $settings = $this->em->find(CommercialSettings::class, 1);
        self::assertSame(8000, $settings->getFreeShippingThresholdCents()); self::assertSame('threshold', $settings->getFreeShippingMode()); self::assertSame(8, $settings->getLowStockThreshold());
        self::assertSame(1, $this->em->getRepository(CommercialSettings::class)->count([]));
    }
    public function testClientsAndOrderDetailsKeepUsefulInformationWithoutPaymentIdentifiers(): void
    {
        $this->client->request('GET', '/admin/user'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('.content-header', 'Clients'); $this->export('clients');
        $this->client->request('GET', '/admin/user/'.$this->admin->getId()); self::assertResponseIsSuccessful(); $this->export('client');
        $order = $this->em->getRepository(Order::class)->findOneBy(['state'=>1]);
        $order->setStripePaymentIntentId('pi_never_show_to_paul'); $this->em->flush();
        $this->client->request('GET', '/admin/order/'.$order->getId()); self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('.content-body', 'pi_never_show_to_paul');
        self::assertSelectorTextContains('.content-body', 'Paiement confirmé'); self::assertSelectorTextContains('.content-body', 'Passer en préparation');
        $this->export('order');
    }
    public function testNormalUsersCannotReachAnyWorkspace(): void
    {
        $this->admin->setRoles(['ROLE_USER']); $this->em->flush(); $this->client->loginUser($this->admin);
        foreach (['/admin', '/admin/modifier-le-site', '/admin/stock', '/admin/promotions-produits', '/admin/regler-le-stock', '/admin/livraison-offerte', '/admin/promo-code/new'] as $url) {
            $this->client->request('GET', $url); self::assertResponseStatusCodeSame(403);
        }
    }
    public function testRetailerCanBeHiddenAndReactivatedWithoutDeletingItsAddress(): void
    {
        $retailer = (new \App\Entity\Revendeur())->setNom('Cave de Paul')->setAdresse('1 rue des Saveurs')->setCodePostal('75002')->setVille('Paris')->setLatitude(48.86)->setLongitude(2.34);
        $this->em->persist($retailer); $this->em->flush(); $id = $retailer->getId();
        $this->client->request('POST', '/admin/revendeur/'.$id.'/visibilite', ['visible'=>'0']); self::assertResponseStatusCodeSame(403);
        foreach (['Masquer'=>false, 'Activer'=>true] as $button=>$visible) {
            $crawler = $this->client->request('GET', '/admin/revendeur'); self::assertResponseIsSuccessful();
            $this->export('retailers-list');
            $form = $crawler->selectButton($button)->form(); $this->client->submit($form); self::assertResponseRedirects();
            $this->em->clear(); $saved = $this->em->find(\App\Entity\Revendeur::class, $id);
            self::assertSame($visible, $saved->isActif()); self::assertSame('1 rue des Saveurs', $saved->getAdresse()); self::assertSame(48.86, $saved->getLatitude());
            $this->client->request('GET', '/nos-revendeurs'); self::assertResponseIsSuccessful();
            self::assertCount($visible ? 1 : 0, $this->client->getCrawler()->filter('[data-retailer]'));
        }
    }
    public function testPackagingFormHasUnitsAndPreservesExistingData(): void
    {
        $crawler = $this->client->request('GET', '/admin/emballage/new'); self::assertResponseIsSuccessful(); $this->export('packaging');
        $form = $crawler->filter('form[name="Emballage"]')->form([
            'Emballage[nom]'=>'Carton 3 bouteilles', 'Emballage[capacite]'=>'3', 'Emballage[poidsVideGrammes]'=>'450',
            'Emballage[longueurCm]'=>'35', 'Emballage[largeurCm]'=>'25', 'Emballage[hauteurCm]'=>'15', 'Emballage[actif]'=>true,
        ]);
        $this->client->submit($form); self::assertResponseRedirects();
        $box = $this->em->getRepository(Emballage::class)->findOneBy(['nom'=>'Carton 3 bouteilles']); self::assertTrue($box->isActif());
        self::assertSame(450, $box->getPoidsVideGrammes()); self::assertSame('35 × 25 × 15 cm', $box->getDimensions());
        $this->client->request('GET', '/admin/revendeur/new'); self::assertResponseIsSuccessful(); $this->export('retailer');
        $this->client->request('GET', '/admin/promo-code/new'); self::assertResponseIsSuccessful(); $this->export('code');
    }
}
