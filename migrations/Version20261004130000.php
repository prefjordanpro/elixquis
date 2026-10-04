<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Historique des demandes d’annulation client et de leur résolution.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cancellation_request (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, resolved_by_id INT DEFAULT NULL, requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', resolved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', reason LONGTEXT DEFAULT NULL, decision VARCHAR(16) NOT NULL, previous_state INT NOT NULL, INDEX IDX_CANCELLATION_ORDER (order_id), INDEX IDX_CANCELLATION_ADMIN (resolved_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cancellation_request ADD CONSTRAINT FK_CANCELLATION_ORDER FOREIGN KEY (order_id) REFERENCES `order` (id)');
        $this->addSql('ALTER TABLE cancellation_request ADD CONSTRAINT FK_CANCELLATION_ADMIN FOREIGN KEY (resolved_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE cancellation_request'); }
}
