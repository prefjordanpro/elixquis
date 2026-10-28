<?php
namespace App\Tests;

use App\Entity\{HomePageContent, Header, Revendeur, User};
use App\Service\RevendeurGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class HomeCmsRevendeursTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private array $uploadedPaths = [];
    private array $geocodingResponses = [];
    private int $geocodingRequests = 0;
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        static::getContainer()->set(RevendeurGeocoder::class, new RevendeurGeocoder(new MockHttpClient(function () {
            $this->geocodingRequests++;
            return new MockResponse(array_shift($this->geocodingResponses) ?? '{"features":[]}');
        })));
    }
    protected function tearDown(): void
    {
        foreach ($this->uploadedPaths as $path) { if (is_file($path)) { unlink($path); } }
        parent::tearDown();
    }
    private function login(bool $admin = true): void
    {
        $user = (new User())->setEmail(($admin ? 'admin' : 'client').'@example.test')->setFirstname('Test')->setLastname('CMS')->setPassword('unused')->setRoles($admin ? ['ROLE_ADMIN'] : ['ROLE_USER']);
        $this->em->persist($user); $this->em->flush(); $this->client->loginUser($user);
    }
    private function retailer(string $nom, bool $actif = true): Revendeur
    {
        $retailer = (new Revendeur())->setNom($nom)->setAdresse('1 rue de la Paix')->setCodePostal('75002')->setVille('Paris')->setActif($actif);
        $this->em->persist($retailer);
        return $retailer;
    }
    private function export(string $name): void
    {
        if ($directory = getenv('CMS_REVIEW_DIR')) {
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/'.$name.'.html', $this->client->getResponse()->getContent());
        }
    }
    public function testDefaultsKeepHomepageAndSharedNavigationVisible(): void
    {
        $this->em->persist((new \App\Entity\Category())->setName('Fruits')->setSlug('fruits'));
        $this->em->flush();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le caractère');
        self::assertSelectorTextContains('.site-announcement', 'Livraison en France');
        self::assertSelectorExists('.home-story img[src="/images/editorial/degustation-ambree-v2.jpg"]');
        self::assertSelectorExists('#navbarCollapse .nav-link[href="/nos-revendeurs"]');
        self::assertSelectorTextContains('#navbarCollapse .dropdown-toggle', 'Nos saveurs');
        self::assertSelectorExists('.navbar-toggler[data-bs-target="#navbarCollapse"]');
        self::assertSelectorNotExists('script[src*="leaflet"]');
        self::assertSame(0, $this->em->getRepository(HomePageContent::class)->count([]));
        $this->export('home');
    }
    public function testStoredContentEscapesMarkupAndControlsAllSections(): void
    {
        $home = (new HomePageContent())->setHeroTitle('<script>alert(1)</script>')->setHeroUrl('/nos-revendeurs')->setStoryCaption('Légende administrée')->setPassionParagraph('Notre histoire administrée')->setAnnouncementEnabled(false)->setPaymentTitle('Paiement administré');
        $this->em->persist($home); $this->em->flush();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '<script>alert(1)</script>');
        self::assertSelectorNotExists('h1 script');
        self::assertSelectorExists('.hero-actions a[href="/nos-revendeurs"]');
        self::assertSelectorTextContains('.home-story figcaption', 'Légende administrée');
        self::assertSelectorTextContains('.brand-story', 'Notre histoire administrée');
        self::assertSelectorTextContains('.reassurance', 'Paiement administré');
        self::assertSelectorNotExists('.site-announcement');
    }
    public function testSlidesRespectActivationOrderingAndAlt(): void
    {
        foreach ([['Dernière', 20, true], ['Première', 1, true], ['Masquée', 0, false]] as [$title, $position, $active]) {
            $this->em->persist((new Header())->setTitle($title)->setContent('Texte')->setButtonTitle('Découvrir')->setButtonLink('/catalogue')->setIllustration('test.jpg')->setAlt('Alternative '.$title)->setPosition($position)->setActive($active));
        }
        $this->em->flush(); $crawler = $this->client->request('GET', '/');
        self::assertCount(2, $crawler->filter('.carousel-item'));
        self::assertSelectorTextContains('.carousel-item.active', 'Première');
        self::assertSelectorExists('.carousel-item.active img[alt="Alternative Première"]');
        self::assertStringNotContainsString('Masquée', $this->client->getResponse()->getContent());
    }
    public function testRetailerPageWithoutRecordsAndCurrentNavigation(): void
    {
        $this->client->request('GET', '/nos-revendeurs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nos revendeurs.');
        self::assertSelectorTextContains('#retailers-list', 'Aucun revendeur');
        self::assertSelectorExists('#navbarCollapse a[href="/nos-revendeurs"][aria-current="page"]');
        self::assertSelectorExists('script[src*="vendor/leaflet/leaflet.js"]');
    }
    public function testActiveRetailersAndCoordinatesAreRenderedWithoutGeocoding(): void
    {
        $this->retailer('Cave active')->setLatitude(48.868)->setLongitude(2.331)->setTelephone('01 23 45 67 89')->setSiteWeb('https://example.test');
        $this->retailer('Sans coordonnées');
        $this->retailer('Invisible', false)->setLatitude(45)->setLongitude(3);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/nos-revendeurs');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-retailer]'));
        self::assertCount(1, $crawler->filter('[data-lat="48.868"][data-lng="2.331"]'));
        self::assertStringNotContainsString('Invisible', $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('.retailer-address', '75002 Paris');
        self::assertSelectorExists('.retailer-website[href="https://example.test"]');
        self::assertSame(0, $this->geocodingRequests);
        $this->export('retailers');
    }
    public function testNonAdminsCannotReachContentCrud(): void
    {
        foreach (['/admin/accueil-du-site', '/admin/home-page-content', '/admin/revendeur', '/admin/header'] as $url) {
            $this->client->request('GET', $url); self::assertResponseRedirects();
        }
        $this->login(false);
        foreach (['/admin/accueil-du-site', '/admin/home-page-content', '/admin/revendeur', '/admin/header'] as $url) {
            $this->client->request('GET', $url); self::assertResponseStatusCodeSame(403);
        }
    }
    public function testAdminCanEditStructuredContentAndReplaceImage(): void
    {
        $this->login();
        $this->client->request('GET', '/admin/accueil-du-site'); self::assertResponseRedirects();
        $crawler = $this->client->followRedirect(); self::assertResponseIsSuccessful();
        $this->export('admin-home');
        self::assertSelectorExists('input[name="HomePageContent[heroTitle]"]');
        self::assertSelectorExists('img.content-image-preview[src="/images/editorial/degustation-ambree-v2.jpg"]');
        self::assertSelectorNotExists('textarea[name="HomePageContent[value]"]');
        $form = $crawler->filter('form[name="HomePageContent"]')->form();
        $form['HomePageContent[heroTitle]'] = 'Titre depuis EasyAdmin';
        $temp = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($temp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $form['HomePageContent[storyImage][file]']->upload($temp);
        $this->client->submit($form); self::assertResponseRedirects();
        $home = $this->em->find(HomePageContent::class, 'home'); $this->em->refresh($home);
        self::assertSame('Titre depuis EasyAdmin', $home->getHeroTitle());
        self::assertMatchesRegularExpression('~^uploads/[a-f0-9]{40}\.png$~', $home->getStoryImage());
        $path = dirname(__DIR__).'/public/'.$home->getStoryImage(); $this->uploadedPaths[] = $path;
        self::assertFileExists($path);
        self::assertFileExists(dirname(__DIR__).'/public/images/editorial/degustation-ambree-v2.jpg');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'Titre depuis EasyAdmin');
        self::assertSelectorExists('.home-story img[src="/'.$home->getStoryImage().'"]');
        if (is_file($temp)) unlink($temp);
    }
    public function testInvalidUploadAndUnsafeDestinationAreRejected(): void
    {
        $this->login(); $this->client->request('GET', '/admin/accueil-du-site');
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[name="HomePageContent"]')->form();
        $form['HomePageContent[heroUrl]'] = 'javascript:alert(1)';
        $temp = tempnam(sys_get_temp_dir(), 'cms').'.jpg'; file_put_contents($temp, '<?php echo "invalid image";');
        $form['HomePageContent[storyImage][file]']->upload($temp);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback');
        $this->em->clear();
        self::assertSame('images/editorial/degustation-ambree-v2.jpg', $this->em->find(HomePageContent::class, 'home')->getStoryImage());
        if (is_file($temp)) unlink($temp);
    }
    public function testAdminRetailerGeocodesWithMockAndCanOverrideManually(): void
    {
        $this->login();
        $this->geocodingResponses = array_fill(0, 2, '{"features":[{"geometry":{"type":"Point","coordinates":[2.33,48.86]},"properties":{"score":0.9}}]}');
        $crawler = $this->client->request('GET', '/admin/revendeur/new'); self::assertResponseIsSuccessful();
        $this->export('admin-retailer');
        $form = $crawler->filter('form[name="Revendeur"]')->form();
        $form['Revendeur[nom]'] = 'Cave test'; $form['Revendeur[adresse]'] = '1 rue de la Paix';
        $form['Revendeur[codePostal]'] = '75002'; $form['Revendeur[ville]'] = 'Paris';
        $this->client->submit($form); self::assertResponseRedirects();
        $retailer = $this->em->getRepository(Revendeur::class)->findOneBy(['nom' => 'Cave test']);
        self::assertSame(48.86, $retailer->getLatitude()); self::assertSame(1, $this->geocodingRequests);
        $crawler = $this->client->request('GET', '/admin/revendeur/'.$retailer->getId().'/edit');
        $form = $crawler->filter('form[name="Revendeur"]')->form();
        $form['Revendeur[latitude]'] = '47.2'; $form['Revendeur[longitude]'] = '1.2';
        $this->client->submit($form); self::assertResponseRedirects();
        $retailer = $this->em->find(Revendeur::class, $retailer->getId());
        $this->em->refresh($retailer); self::assertSame(47.2, $retailer->getLatitude()); self::assertSame(1, $this->geocodingRequests);
        $crawler = $this->client->request('GET', '/admin/revendeur/'.$retailer->getId().'/edit');
        $form = $crawler->filter('form[name="Revendeur"]')->form(); $form['Revendeur[adresse]'] = '2 rue de la Paix';
        $this->client->submit($form); self::assertResponseRedirects(); self::assertSame(2, $this->geocodingRequests);
    }
    public function testFailedAdminGeocodingKeepsRetailerInTheAccessibleList(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin/revendeur/new');
        $form = $crawler->filter('form[name="Revendeur"]')->form();
        $form['Revendeur[nom]'] = 'Adresse à corriger'; $form['Revendeur[adresse]'] = 'Adresse introuvable';
        $form['Revendeur[codePostal]'] = '75002'; $form['Revendeur[ville]'] = 'Paris';
        $this->client->submit($form); self::assertResponseRedirects();
        $retailer = $this->em->getRepository(Revendeur::class)->findOneBy(['nom'=>'Adresse à corriger']);
        self::assertNotNull($retailer); self::assertNull($retailer->getLatitude());
        $this->client->request('GET', '/nos-revendeurs'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#retailers-list', 'Adresse à corriger');
        self::assertSelectorNotExists('[data-lat]');
    }
    public function testCleanDatabaseMappingAndSchemaAreConsistent(): void
    {
        $validator = new \Doctrine\ORM\Tools\SchemaValidator($this->em);
        self::assertSame([], $validator->validateMapping());
        $manager = $this->em->getConnection()->createSchemaManager();
        $actual = $manager->introspectSchema();
        $expected = (new SchemaTool($this->em))->getSchemaFromMetadata($this->em->getMetadataFactory()->getAllMetadata());
        // SQLite reports every INTEGER PRIMARY KEY as auto-generated, even this fixed singleton id.
        $actual->getTable('commercial_settings')->getColumn('id')->setAutoincrement(false);
        self::assertTrue($manager->createComparator()->compareSchemas($actual, $expected)->isEmpty());
    }
}
