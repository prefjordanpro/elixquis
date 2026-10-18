<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ajouts seuls ; valeurs initiales figées pour préserver le rendu existant. */
final class Version20261018110413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion commerciale additive : promotions, codes, livraison offerte et contenus initiaux de la homepage.';
    }

    private const CONTENT = array (
  'announcement' => 'Rhum arrangé artisanal • Livraison en France',
  'hero_label' => 'Rhums arrangés artisanaux',
  'hero_title' => 'Le caractère
du rhum.',
  'hero_italic' => 'L’exquis des fruits.',
  'hero_description' => 'Des rhums arrangés, des fruits et des épices. Un univers gourmand à découvrir, pour des moments conviviaux.',
  'hero_cta' => 'Découvrir la boutique',
  'hero_url' => '/catalogue',
  'hero_image' => 'images/editorial/degustation-ambree-v2.jpg',
  'hero_alt' => 'Illustration d’ambiance : un verre ambré et un zeste d’orange',
  'hero_note' => 'Fruits · Épices · Caractère',
  'story_label' => 'L’esprit Elixquis',
  'story_title' => 'L’élixir.',
  'story_italic' => 'L’exquis.',
  'story_intro' => 'Deux mots, une invitation à découvrir.',
  'story_text' => 'Elixquis associe l’univers de l’élixir à la délicatesse de l’exquis. Les fruits et les épices y rencontrent le caractère du rhum, avec une place essentielle accordée aux ingrédients naturels.',
  'story_cta' => 'Explorer les recettes',
  'story_url' => '/catalogue',
  'story_image' => 'images/editorial/degustation-ambree-v2.jpg',
  'story_alt' => 'Illustration d’ambiance : lumière chaude sur un verre de dégustation',
  'story_caption' => 'Le goût des moments partagés',
  'selection_label' => 'La sélection Elixquis',
  'selection_title' => 'À découvrir, à savourer.',
  'selection_text' => 'Explorez les produits mis en avant dans notre boutique.',
  'selection_cta' => 'Voir toute la boutique ↗',
  'flavours_label' => 'À chacun ses envies',
  'flavours_title' => 'Choisissez vos saveurs.',
  'flavours_cta' => 'Voir toutes les saveurs',
  'passion_label' => 'L’histoire d’un passionné',
  'passion_title' => 'Tout commence
par une',
  'passion_italic' => 'découverte.',
  'passion_text' => 'Lors d’un séjour au ski entre amis, Paul découvre les rhums arrangés. Le mélange du rhum, des fruits et des épices lui donne envie de créer ses propres compositions, d’abord pour lui et ses proches.',
  'passion_second' => 'Ces moments partagés font naître Elixquis : une passion des saveurs et l’envie de la transmettre.',
  'passion_image' => 'images/editorial/fruits-epices-v2.jpg',
  'passion_alt' => 'Illustration d’ambiance : oranges, litchis, vanille et cannelle sur une table claire',
  'passion_caption' => 'Fruits & épices · L’inspiration',
);

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration destinée à MariaDB/MySQL.');
        $this->addSql('ALTER TABLE order_detail ADD commercial_discount_cents INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE commercial_settings (id INT NOT NULL, low_stock_threshold INT DEFAULT 5 NOT NULL, free_shipping_mode VARCHAR(20) DEFAULT \'disabled\' NOT NULL, free_shipping_threshold_cents INT DEFAULT 0 NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE homepage_content (id VARCHAR(50) NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE promo_code (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(150) NOT NULL, active TINYINT(1) DEFAULT 0 NOT NULL, type VARCHAR(10) NOT NULL, value INT NOT NULL, starts_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', minimum_cents INT DEFAULT NULL, usage_limit INT DEFAULT NULL, uses INT DEFAULT 0 NOT NULL, stackable TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_3D8C939E77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE `order` ADD promo_code VARCHAR(50) DEFAULT NULL, ADD promo_discount_cents INT DEFAULT 0 NOT NULL, ADD transport_cost_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD promotion_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD promotion_price_cents INT DEFAULT NULL, ADD promotion_starts_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD promotion_ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql("INSERT INTO commercial_settings (id, low_stock_threshold, free_shipping_mode, free_shipping_threshold_cents) VALUES (1, 5, 'disabled', 0)");
        foreach (self::CONTENT as $key => $value) {
            $this->addSql('INSERT INTO homepage_content (id, value) VALUES (?, ?)', [$key, $value]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Migration additive : conserver les contenus et snapshots commerciaux.');
    }
    public function isTransactional(): bool { return false; }
}
