<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accueil singleton dans la table existante, bannières ordonnées et revendeurs. Aucune suppression de données.';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS homepage_content (id VARCHAR(50) NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE revendeur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, adresse VARCHAR(255) NOT NULL, complement_adresse VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(255) NOT NULL, ville VARCHAR(255) NOT NULL, pays VARCHAR(255) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, telephone VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, site_web VARCHAR(255) DEFAULT NULL, actif TINYINT(1) NOT NULL, priorite INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE header ADD active TINYINT(1) DEFAULT 1 NOT NULL, ADD position INT DEFAULT 0 NOT NULL, ADD alt VARCHAR(255) DEFAULT \'\' NOT NULL');

        // Import legacy keys when upgrading a database used by an earlier CMS branch.
        // The source rows and image paths are deliberately preserved.
        $legacy = $schema->hasTable('homepage_content') ? $this->connection->fetchAllKeyValue('SELECT id, value FROM homepage_content') : [];
        if (isset($legacy['home'])) { return; }
        $sections = self::defaults();
        $map = [
            'announcement' => ['announcement', 'announcementMessage'],
            'hero_label' => ['hero', 'heroEyebrow'], 'hero_italic' => ['hero', 'heroAccent'],
            'hero_description' => ['hero', 'heroParagraph'], 'hero_cta' => ['hero', 'heroCta'],
            'hero_url' => ['hero', 'heroUrl'], 'hero_image' => ['hero', 'heroImage'], 'hero_alt' => ['hero', 'heroAlt'], 'hero_note' => ['hero', 'heroNote'],
            'story_label' => ['story', 'storyEyebrow'], 'story_title' => ['story', 'storyTitle'], 'story_italic' => ['story', 'storyAccent'],
            'story_intro' => ['story', 'storySubtitle'], 'story_text' => ['story', 'storyParagraph'], 'story_cta' => ['story', 'storyLinkLabel'],
            'story_url' => ['story', 'storyUrl'], 'story_caption' => ['story', 'storyCaption'], 'story_image' => ['story', 'storyImage'], 'story_alt' => ['story', 'storyAlt'],
            'selection_label' => ['selection', 'selectionEyebrow'], 'selection_title' => ['selection', 'selectionTitle'], 'selection_text' => ['selection', 'selectionDescription'], 'selection_cta' => ['selection', 'selectionLinkLabel'],
            'flavours_label' => ['flavours', 'flavoursEyebrow'], 'flavours_title' => ['flavours', 'flavoursTitle'], 'flavours_cta' => ['flavours', 'flavoursCta'],
            'passion_label' => ['passion', 'passionEyebrow'], 'passion_italic' => ['passion', 'passionAccent'], 'passion_text' => ['passion', 'passionParagraph'],
            'passion_second' => ['passion', 'passionSecondParagraph'], 'passion_image' => ['passion', 'passionImage'], 'passion_alt' => ['passion', 'passionAlt'], 'passion_caption' => ['passion', 'passionCaption'],
        ];
        foreach ($map as $old => [$section, $key]) {
            if (array_key_exists($old, $legacy)) { $sections[$section][$key] = $legacy[$old]; }
        }
        foreach (['hero', 'passion'] as $section) {
            if (isset($legacy[$section.'_title'])) {
                $lines = preg_split('/\R/u', $legacy[$section.'_title'], 2);
                $sections[$section][$section.'Title'] = $lines[0];
                $sections[$section][$section.'TitleSecondLine'] = $lines[1] ?? '';
            }
        }
        $this->addSql('INSERT INTO homepage_content (id, value) VALUES (?, ?)', ['home', json_encode($sections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    private static function defaults(): array
    {
        // Frozen migration payload, independent of future entity changes.
        return json_decode(file_get_contents(__DIR__.'/data/home-20261028.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les contenus éditoriaux et les revendeurs doivent être conservés.');
    }
}
