<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aligne le nom de l’index order_notification.order_id sur le mapping Doctrine, sans modifier les données ni la clé étrangère.';
    }

    public function up(Schema $schema): void
    {
        // Create the replacement first so the foreign key always has a supporting index.
        $this->addSql('CREATE INDEX IDX_BB94E2A08D9F6D38 ON order_notification (order_id)');
        $this->addSql('DROP INDEX IDX_ORDER_NOTIFICATION_ORDER ON order_notification');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_ORDER_NOTIFICATION_ORDER ON order_notification (order_id)');
        $this->addSql('DROP INDEX IDX_BB94E2A08D9F6D38 ON order_notification');
    }
}
