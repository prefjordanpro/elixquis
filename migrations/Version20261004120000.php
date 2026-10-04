<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Conserve le paiement et le remboursement Stripe sans modifier les commandes historiques.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, ADD stripe_refund_id VARCHAR(255) DEFAULT NULL, ADD stripe_refund_status VARCHAR(32) DEFAULT NULL, ADD state_before_refund INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORDER_PAYMENT_INTENT ON `order` (stripe_payment_intent_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORDER_REFUND ON `order` (stripe_refund_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ORDER_PAYMENT_INTENT ON `order`');
        $this->addSql('DROP INDEX UNIQ_ORDER_REFUND ON `order`');
        $this->addSql('ALTER TABLE `order` DROP stripe_payment_intent_id, DROP stripe_refund_id, DROP stripe_refund_status, DROP state_before_refund');
    }
}
