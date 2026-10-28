<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pages légales : brouillons, versions publiées conservées et référence de CGV acceptée sur les nouvelles commandes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE legal_page (id VARCHAR(30) NOT NULL, published_version_id INT DEFAULT NULL, draft_title VARCHAR(255) NOT NULL, draft_content LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revision INT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_39715897B5D68A8D (published_version_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE legal_version (id INT AUTO_INCREMENT NOT NULL, page_id VARCHAR(30) NOT NULL, published_by_id INT DEFAULT NULL, number INT NOT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, source_content LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', published_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', publisher_label VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_LEGAL_PAGE_NUMBER (page_id, number), INDEX IDX_78274DADC4663E4 (page_id), INDEX IDX_78274DAD5B075477 (published_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE legal_page ADD CONSTRAINT FK_39715897B5D68A8D FOREIGN KEY (published_version_id) REFERENCES legal_version (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE legal_version ADD CONSTRAINT FK_78274DADC4663E4 FOREIGN KEY (page_id) REFERENCES legal_page (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE legal_version ADD CONSTRAINT FK_78274DAD5B075477 FOREIGN KEY (published_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `order` ADD accepted_terms_version_id INT DEFAULT NULL, ADD terms_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398B477D91 FOREIGN KEY (accepted_terms_version_id) REFERENCES legal_version (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_F5299398B477D91 ON `order` (accepted_terms_version_id)');
        $initial = file_get_contents(__DIR__.'/data/cgv-initial-20261028.html');
        foreach (['cgv' => 'CONDITIONS GÉNÉRALES DE VENTE', 'mentions' => 'Mentions légales', 'confidentialite' => 'Politique de confidentialité', 'cookies' => 'Politique relative aux cookies'] as $type => $title) {
            $this->addSql('INSERT INTO legal_page (id, draft_title, draft_content, created_at, updated_at, revision) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)', [$type, $title, $type === 'cgv' ? $initial : '']);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les documents contractuels et les preuves d’acceptation des commandes.');
    }
}
