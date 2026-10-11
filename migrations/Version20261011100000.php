<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Suivi des nouvelles tentatives Stripe sans qualifier rétroactivement les anciennes commandes.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD payment_tracking_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD payment_initiated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD stripe_checkout_request JSON DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP payment_tracking_enabled, DROP payment_initiated_at, DROP stripe_checkout_request');
    }
}
