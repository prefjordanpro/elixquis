<?php
namespace App\Tests;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__).'/migrations/Version20261028090000.php';

final class HomeCmsMigrationTest extends TestCase
{
    public function testLegacyValuesAndSharedImagesAreImportedWithoutDestructiveSql(): void
    {
        $db = DriverManager::getConnection(['driver'=>'pdo_sqlite', 'memory'=>true]);
        $db->executeStatement('CREATE TABLE homepage_content (id VARCHAR(50) PRIMARY KEY, value TEXT NOT NULL)');
        $db->insert('homepage_content', ['id'=>'hero_title', 'value'=>"Titre existant\nSuite existante"]);
        $db->insert('homepage_content', ['id'=>'story_image', 'value'=>'uploads/shared.webp']);
        $schema = new Schema(); $schema->createTable('homepage_content');
        $migration = new \DoctrineMigrations\Version20261028090000($db, new NullLogger());
        $migration->up($schema);
        foreach ($migration->getSql() as $query) {
            self::assertDoesNotMatchRegularExpression('/\b(DROP|DELETE|TRUNCATE)\b/i', $query->getStatement());
            if (str_starts_with($query->getStatement(), 'INSERT INTO homepage_content')) {
                $db->executeStatement($query->getStatement(), $query->getParameters());
            }
        }
        $value = json_decode($db->fetchOne('SELECT value FROM homepage_content WHERE id = ?', ['home']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Titre existant', $value['hero']['heroTitle']);
        self::assertSame('Suite existante', $value['hero']['heroTitleSecondLine']);
        self::assertSame('uploads/shared.webp', $value['story']['storyImage']);
        self::assertNotEmpty($value['passion']['passionParagraph']);
        self::assertSame('uploads/shared.webp', $db->fetchOne('SELECT value FROM homepage_content WHERE id = ?', ['story_image']));
        $db->close();
    }
    public function testMigrationInitializesFullContentOnAnEmptyDatabase(): void
    {
        $db = DriverManager::getConnection(['driver'=>'pdo_sqlite', 'memory'=>true]);
        $migration = new \DoctrineMigrations\Version20261028090000($db, new NullLogger());
        $migration->up(new Schema());
        $insert = array_values(array_filter($migration->getSql(), static fn ($q) => str_starts_with($q->getStatement(), 'INSERT INTO homepage_content')))[0];
        $value = json_decode($insert->getParameters()[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Le caractère', $value['hero']['heroTitle']);
        self::assertSame('images/editorial/degustation-ambree-v2.jpg', $value['story']['storyImage']);
        self::assertNotEmpty($value['reassurance']['paymentTitle']);
        $db->close();
    }
}
