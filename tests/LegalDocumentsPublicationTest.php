<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{LegalPage, LegalVersion, User};
use App\Service\LegalContent;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

require_once dirname(__DIR__).'/migrations/Version20261029110000.php';

final class LegalDocumentsPublicationTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        static::getContainer()->get(LegalContent::class)->initialize();
    }

    private function migrate(): void
    {
        $migration = new \DoctrineMigrations\Version20261029110000($this->em->getConnection(), new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            self::assertDoesNotMatchRegularExpression('/\b(DELETE|DROP|TRUNCATE)\b/i', $query->getStatement());
            $this->em->getConnection()->executeStatement($query->getStatement(), $query->getParameters());
        }
        $this->em->clear();
    }

    public function testMigrationPublishesTwoDocumentsWithoutChangingCgvOrPublishingIncompleteCookies(): void
    {
        $content = static::getContainer()->get(LegalContent::class);
        $cgv = $this->em->find(LegalPage::class, 'cgv');
        $version = $content->publish($cgv, $cgv->getRevision(), null);
        $cgvId = $version->getId(); $cgvContent = $version->getContent();
        $this->migrate();
        self::assertSame(4, $this->em->getRepository(LegalPage::class)->count([]));
        self::assertSame(3, $this->em->getRepository(LegalVersion::class)->count([]));
        self::assertSame($cgvId, $this->em->find(LegalPage::class, 'cgv')->getPublishedVersion()->getId());
        self::assertSame($cgvContent, $this->em->find(LegalPage::class, 'cgv')->getPublishedVersion()->getContent());
        foreach (['mentions', 'confidentialite'] as $type) {
            $page = $this->em->find(LegalPage::class, $type);
            self::assertSame(1, $page->getPublishedVersion()->getNumber());
            self::assertSame('Publiée', $page->getPublishedVersion()->getStatus());
            self::assertFalse($page->hasChanges());
            self::assertNotNull($page->getPublishedVersion()->getPublishedAt());
        }
        self::assertNull($this->em->find(LegalPage::class, 'cookies')->getPublishedVersion());
        $crawler = $this->client->request('GET', '/mentions-legales'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.legal-document-body', 'FR25 989318589');
        self::assertSelectorTextContains('.legal-document-body', 'OVH SAS');
        self::assertSame(['/conditions-generales-de-vente', '/mentions-legales', '/politique-de-confidentialite', '/retractation'], $crawler->filter('.footer-grid > div:last-child a')->each(static fn ($node) => $node->attr('href')));
        if ($directory = getenv('LEGAL_REVIEW_DIR')) file_put_contents($directory.'/mentions-published.html', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/politique-de-confidentialite'); self::assertResponseIsSuccessful();
        foreach (['OpenStreetMap Foundation', 'Mapbox', 'Géoplateforme', 'Stripe', 'Sendcloud', 'Mailjet', 'OVHcloud', 'ni inscription à une newsletter'] as $term) self::assertSelectorTextContains('.legal-document-body', $term);
        foreach (['[DATE DE PUBLICATION]', 'complément d’adresse', 'Le fournisseur réellement utilisé doit être identifié'] as $stale) self::assertSelectorTextNotContains('.legal-document-body', $stale);
        if ($directory = getenv('LEGAL_REVIEW_DIR')) file_put_contents($directory.'/privacy-published.html', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/politique-cookies'); self::assertResponseStatusCodeSame(404);
        $this->migrate();
        self::assertSame(3, $this->em->getRepository(LegalVersion::class)->count([]));
    }

    public function testFuturePrivacyDraftDoesNotReplaceThePublishedDocument(): void
    {
        $this->migrate();
        $page = $this->em->find(LegalPage::class, 'confidentialite');
        $original = $page->getPublishedVersion()->getContent();
        static::getContainer()->get(LegalContent::class)->saveDraft($page, 'Nouvelle politique', '<p>Texte non publié.</p>', $page->getRevision());
        $this->client->request('GET', '/politique-de-confidentialite'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.legal-document-body', 'OpenStreetMap Foundation');
        self::assertSelectorTextNotContains('.legal-document-body', 'Texte non publié');
        self::assertSame($original, $this->em->find(LegalPage::class, 'confidentialite')->getPublishedVersion()->getContent());
    }

    public function testMigrationRefusesToOverwriteACustomDraft(): void
    {
        $page = $this->em->find(LegalPage::class, 'mentions');
        static::getContainer()->get(LegalContent::class)->saveDraft($page, 'Titre personnalisé', '<p>Brouillon personnel.</p>', $page->getRevision());
        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class);
        $this->migrate();
    }

    public function testMentionsHtmlPreservesTheEntireSuppliedText(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/migrations/data/mentions-initial-20261029.txt');
        $source = preg_replace('/^#{1,3} /m', '', str_replace('**', '', $source));
        $html = file_get_contents(dirname(__DIR__).'/migrations/data/mentions-initial-20261029.html');
        $text = 'MENTIONS LÉGALES '.html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</h2>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalize = static fn ($text) => trim(preg_replace('/\s+/u', ' ', $text));
        self::assertSame($normalize($source), $normalize($text));
    }

    public function testLegalAdministrationRequiresAdminAndShowsThePublishedDocuments(): void
    {
        $this->migrate();
        $admin = (new User())->setEmail('legal-docs-admin@example.test')->setFirstname('Paul')->setLastname('Test')->setPassword('unused')->setRoles(['ROLE_ADMIN']);
        $user = (new User())->setEmail('legal-docs-user@example.test')->setFirstname('Client')->setLastname('Test')->setPassword('unused');
        $this->em->persist($admin); $this->em->persist($user); $this->em->flush();
        $this->client->loginUser($user);
        foreach (['mentions', 'confidentialite'] as $type) {
            $this->client->request('GET', '/admin/pages-legales/'.$type.'/modifier'); self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', '/admin/pages-legales/'.$type.'/publier'); self::assertResponseStatusCodeSame(403);
        }
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/pages-legales'); self::assertResponseIsSuccessful();
        foreach (['Mentions légales', 'Politique de confidentialité'] as $label) {
            $panel = $crawler->filter('.workspace-panel')->reduce(static fn ($node) => $node->filter('h2')->text() === $label);
            self::assertStringContainsString('Publiées', $panel->text());
            self::assertStringContainsString('Version 1', $panel->text());
        }
    }
}
