<?php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011160000 extends AbstractMigration
{
    public function getDescription(): string { return 'File durable des emails transactionnels de commande, sans modification des données historiques.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE order_notification (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, state INT NOT NULL, payload JSON NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_ORDER_NOTIFICATION_ORDER (order_id), UNIQUE INDEX UNIQ_ORDER_NOTIFICATION_STATE (order_id, state), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE order_notification ADD CONSTRAINT FK_ORDER_NOTIFICATION_ORDER FOREIGN KEY (order_id) REFERENCES `order` (id)');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE order_notification'); }
}
