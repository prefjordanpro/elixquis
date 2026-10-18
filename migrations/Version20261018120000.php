<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Ajoute le pourcentage promotionnel produit ; conserve les anciens prix fixes et les commandes historiques.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform, 'Migration destinée à MariaDB/MySQL.');
        $this->addSql('ALTER TABLE product ADD promotion_percent INT DEFAULT NULL');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver les promotions configurées.'); }
}
