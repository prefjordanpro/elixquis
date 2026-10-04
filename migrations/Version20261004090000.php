<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du stock, de la disponibilité et des réservations sans modification des anciennes commandes.';
    }

    public function up(Schema $schema): void
    {
        // Conserver le premier lien, rendre chaque doublon accessible sans supprimer de produit.
        foreach (['product', 'category'] as $table) {
            $rows = $this->connection->fetchAllAssociative('SELECT id, slug FROM '.$table.' ORDER BY id');
            $used = array_fill_keys(array_column($rows, 'slug'), true);
            $seen = [];
            foreach ($rows as $row) {
                if (isset($seen[$row['slug']])) {
                    $slug = substr($row['slug'], 0, 220).'-'.$row['id'];
                    while (isset($used[$slug])) { $slug .= '-bis'; }
                    $this->addSql('UPDATE '.$table.' SET slug = ? WHERE id = ?', [$slug, $row['id']]);
                    $used[$slug] = true;
                }
                $seen[$row['slug']] = true;
            }
        }
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04AD989D9B62 ON product (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C1989D9B62 ON category (slug)');
        $this->addSql('ALTER TABLE product ADD stock INT DEFAULT 0 NOT NULL, ADD is_active TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE product ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE `order` ADD stock_reserved TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE carrier ADD tva DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE `order` ADD carrier_tva_rate DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE order_detail ADD product_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_ED896F464584665A ON order_detail (product_id)');
        $this->addSql('ALTER TABLE order_detail ADD CONSTRAINT FK_ED896F464584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les réservations et stocks doivent être conservés.');
    }
}
