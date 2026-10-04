<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Livraison Sendcloud : poids, instantanés et expédition unique par commande.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD shipping_weight_grams INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `order` ADD shipping_snapshot JSON DEFAULT NULL');
        $this->addSql('CREATE TABLE shipment (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, state VARCHAR(24) NOT NULL, sendcloud_id VARCHAR(255) DEFAULT NULL, parcel_id INT DEFAULT NULL, tracking_number VARCHAR(255) DEFAULT NULL, tracking_url LONGTEXT DEFAULT NULL, status_code VARCHAR(64) DEFAULT NULL, requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', has_label TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_SHIPMENT_ORDER (order_id), UNIQUE INDEX UNIQ_SHIPMENT_REMOTE (sendcloud_id), UNIQUE INDEX UNIQ_SHIPMENT_PARCEL (parcel_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE shipment ADD CONSTRAINT FK_SHIPMENT_ORDER FOREIGN KEY (order_id) REFERENCES `order` (id)');
        $this->addSql('ALTER TABLE shipment ADD request_reference VARCHAR(64) NOT NULL, ADD UNIQUE INDEX UNIQ_SHIPMENT_REQUEST (request_reference)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shipment');
        $this->addSql('ALTER TABLE `order` DROP shipping_snapshot');
        $this->addSql('ALTER TABLE product DROP shipping_weight_grams');
    }
}
