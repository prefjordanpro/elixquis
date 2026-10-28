<?php
declare(strict_types=1);
namespace App\Tests;

use App\DataFixtures\RevendeurDemoFixtures;
use App\Entity\Revendeur;
use App\Kernel;
use App\Service\RevendeurGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpClient\MockHttpClient;

final class RevendeurDemoFixturesTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private int $httpRequests = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        static::getContainer()->set(RevendeurGeocoder::class, new RevendeurGeocoder(new MockHttpClient(function () {
            ++$this->httpRequests; throw new \LogicException('Aucun appel de géocodage autorisé dans cette démonstration.');
        })));
    }

    private function runCommand(array $arguments): ApplicationTester
    {
        $application = new Application(self::$kernel); $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($arguments + ['--env' => 'test', '--no-interaction' => true]);
        return $tester;
    }

    private function existingRetailer(): Revendeur
    {
        $retailer = (new Revendeur())->setNom('Revendeur existant à conserver')->setAdresse('Adresse existante')
            ->setCodePostal('75002')->setVille('Paris')->setLatitude(48.86)->setLongitude(2.34);
        $this->em->persist($retailer); $this->em->flush();
        return $retailer;
    }

    public function testFixtureCommandAddsNineFictionalRecordsWithoutPurgingOrDuplicating(): void
    {
        $existing = $this->existingRetailer();
        foreach ([1, 2] as $run) {
            $tester = $this->runCommand(['command'=>'doctrine:fixtures:load', '--group'=>['revendeurs-demo'], '--append'=>true]);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertSame(10, $this->em->getRepository(Revendeur::class)->count([]));
            self::assertSame(8, $this->em->getRepository(Revendeur::class)->count(['actif'=>true, 'adresse'=>RevendeurDemoFixtures::ADDRESS]));
        }
        $existing = $this->em->find(Revendeur::class, $existing->getId());
        $this->em->refresh($existing); self::assertSame('Adresse existante', $existing->getAdresse());
        foreach (RevendeurDemoFixtures::STORES as [$key, $name, $postcode, $city, $lat, $lng, $active]) {
            $retailer = $this->em->getRepository(Revendeur::class)->findOneBy(['email'=>$key.'@'.RevendeurDemoFixtures::DOMAIN]);
            self::assertSame($lat, $retailer->getLatitude()); self::assertSame($lng, $retailer->getLongitude());
            self::assertSame($city, $retailer->getVille()); self::assertSame($postcode, $retailer->getCodePostal());
            self::assertSame($active, $retailer->isActif());
            self::assertStringContainsString('FICTIF', $retailer->getNom());
            self::assertCount(0, static::getContainer()->get('validator')->validate($retailer));
        }
        self::assertSame(0, $this->httpRequests);
    }

    public function testPublicListAndMapShowOnlyEightClearlyFictionalDemoRecords(): void
    {
        static::getContainer()->get(RevendeurDemoFixtures::class)->load($this->em);
        $crawler = $this->client->request('GET', '/nos-revendeurs'); self::assertResponseIsSuccessful();
        self::assertCount(8, $crawler->filter('[data-retailer][data-lat][data-lng]'));
        foreach ($crawler->filter('[data-retailer]') as $card) {
            self::assertStringContainsString('FICTIF (démonstration)', $card->textContent);
            self::assertStringContainsString(RevendeurDemoFixtures::ADDRESS, $card->textContent);
            self::assertStringContainsString(RevendeurDemoFixtures::DISCLAIMER, $card->textContent);
        }
        self::assertStringNotContainsString('Le Comptoir Masqué', $this->client->getResponse()->getContent());
        self::assertCount(3, $crawler->filter('.retailer-website[href$=".invalid"]'));
        self::assertSame(0, $this->httpRequests);
        if ($directory = getenv('DEMO_REVIEW_DIR')) {
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/retailers-demo.html', $this->client->getResponse()->getContent());
        }
    }

    public function testRemoveCommandDeletesOnlyFixtureRecords(): void
    {
        $existing = $this->existingRetailer();
        $fixture = static::getContainer()->get(RevendeurDemoFixtures::class); $fixture->load($this->em);
        $tester = $this->runCommand(['command'=>'app:demo:revendeurs:remove']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('9 revendeur(s)', $tester->getDisplay());
        self::assertSame(1, $this->em->getRepository(Revendeur::class)->count([]));
        $this->em->refresh($existing); self::assertSame('Adresse existante', $existing->getAdresse());
        self::assertSame(0, $fixture->remove($this->em));
    }

    public function testFixtureLoadWithoutAppendIsRejectedBeforePurging(): void
    {
        $existing = $this->existingRetailer();
        $tester = $this->runCommand(['command'=>'doctrine:fixtures:load', '--group'=>['revendeurs-demo']]);
        self::assertNotSame(0, $tester->getStatusCode()); self::assertStringContainsString('--append', $tester->getDisplay());
        self::assertSame(1, $this->em->getRepository(Revendeur::class)->count([]));
        $this->em->refresh($existing); self::assertSame('Adresse existante', $existing->getAdresse());
    }

    public function testDirectFixtureInvocationIsRejectedInProductionBeforeDatabaseAccess(): void
    {
        $manager = $this->createMock(\Doctrine\Persistence\ObjectManager::class);
        $manager->expects(self::never())->method('getRepository');
        foreach (['load', 'remove'] as $method) {
            try { (new RevendeurDemoFixtures('prod'))->$method($manager); self::fail('Production must be refused'); }
            catch (\LogicException $exception) { self::assertStringContainsString('dev et test', $exception->getMessage()); }
        }
    }

    public function testProductionContainerHasNeitherFixturesNorDemoCommands(): void
    {
        $kernel = new class('prod', false) extends Kernel {
            public function getCacheDir(): string { return $this->getProjectDir().'/var/cache/fixtures-prod-isolation'; }
            public function getBuildDir(): string { return $this->getCacheDir(); }
        };
        try {
            $kernel->boot();
            self::assertArrayNotHasKey('DoctrineFixturesBundle', $kernel->getBundles());
            self::assertFalse($kernel->getContainer()->has(RevendeurDemoFixtures::class));
            $application = new Application($kernel);
            self::assertFalse($application->has('doctrine:fixtures:load'));
            self::assertFalse($application->has('app:demo:revendeurs:remove'));
        } finally { $kernel->shutdown(); }
    }
}
