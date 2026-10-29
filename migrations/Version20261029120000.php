<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Factures et avoirs immuables, compteur transactionnel et snapshots pour les nouvelles commandes uniquement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, original_invoice_id INT DEFAULT NULL, number VARCHAR(40) NOT NULL, type VARCHAR(16) NOT NULL, issued_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', paid_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', sale_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', currency VARCHAR(3) NOT NULL, payment_reference VARCHAR(255) DEFAULT NULL, refund_reference VARCHAR(255) DEFAULT NULL, snapshot JSON NOT NULL COMMENT \'(DC2Type:json)\', UNIQUE INDEX UNIQ_9065174496901F54 (number), UNIQUE INDEX UNIQ_9065174437DBF79B (refund_reference), UNIQUE INDEX UNIQ_INVOICE_ORDER_TYPE (order_id, type), INDEX IDX_906517448D9F6D38 (order_id), INDEX IDX_90651744443E8669 (original_invoice_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_line (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, position INT NOT NULL, data JSON NOT NULL COMMENT \'(DC2Type:json)\', UNIQUE INDEX UNIQ_INVOICE_LINE_POSITION (invoice_id, position), INDEX IDX_D3D1D6932989F1FD (invoice_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_sequence (id INT NOT NULL, year INT NOT NULL, invoice_value INT NOT NULL, credit_value INT NOT NULL, last_issued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517448D9F6D38 FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744443E8669 FOREIGN KEY (original_invoice_id) REFERENCES invoice (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT FK_D3D1D6932989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE `order` ADD invoice_customer_snapshot JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD payment_confirmed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE order_detail ADD regular_unit_price_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD search_text LONGTEXT NOT NULL');
        $this->addSql('INSERT INTO invoice_sequence (id, year, invoice_value, credit_value) VALUES (1, 0, 0, 0)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les documents comptables et les informations historiques.');
    }
}
