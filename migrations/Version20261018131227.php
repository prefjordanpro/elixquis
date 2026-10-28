<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Les compteurs commerciaux reflètent les commandes payées ; aucun montant ni statut n'est modifié. */
final class Version20261018131227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte les utilisations après paiement confirmé, avec lien stable et marqueur idempotent sur la commande.';
    }

    public const RECONCILIATION_SQL = [
        'UPDATE `order` SET promo_code_definition_id = (SELECT id FROM promo_code WHERE promo_code.code = `order`.promo_code) WHERE promo_code IS NOT NULL',
        'UPDATE `order` SET promo_usage_counted_at = CURRENT_TIMESTAMP WHERE promo_code IS NOT NULL AND promo_discount_cents > 0 AND (state IN (1, 2, 3, 5, 6, 7) OR (state = 4 AND stripe_payment_intent_id IS NOT NULL))',
        'UPDATE promo_code SET uses = (SELECT COUNT(*) FROM `order` WHERE `order`.promo_code_definition_id = promo_code.id AND `order`.promo_usage_counted_at IS NOT NULL)',
    ];
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration destinée à MariaDB/MySQL.');
        $this->addSql('ALTER TABLE `order` ADD promo_code_definition_id INT DEFAULT NULL, ADD promo_usage_counted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F52993987F80A448 FOREIGN KEY (promo_code_definition_id) REFERENCES promo_code (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_ORDER_PROMO_CODE_DEFINITION ON `order` (promo_code_definition_id)');
        foreach (self::RECONCILIATION_SQL as $sql) { $this->addSql($sql); }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les preuves et compteurs des utilisations confirmées.');
    }
}
