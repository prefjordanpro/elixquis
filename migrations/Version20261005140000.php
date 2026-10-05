<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Ajout des emballages configurables et des colis de commande figés ; aucune modification des anciennes données.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE emballage (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(160) NOT NULL, capacite INT NOT NULL, poids_vide_grammes INT DEFAULT NULL, longueur_cm DOUBLE PRECISION DEFAULT NULL, largeur_cm DOUBLE PRECISION DEFAULT NULL, hauteur_cm DOUBLE PRECISION DEFAULT NULL, poids_max_grammes INT DEFAULT NULL, actif TINYINT(1) NOT NULL, priorite INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE colis_commande (id INT AUTO_INCREMENT NOT NULL, commande_id INT NOT NULL, emballage_id INT DEFAULT NULL, position INT NOT NULL, nom_emballage VARCHAR(160) NOT NULL, capacite INT NOT NULL, nombre_unites INT NOT NULL, poids_produits_grammes INT NOT NULL, poids_emballage_grammes INT NOT NULL, poids_total_grammes INT NOT NULL, dimensions JSON NOT NULL, contenu JSON NOT NULL, sendcloud_id VARCHAR(255) DEFAULT NULL, sendcloud_parcel_id INT DEFAULT NULL, numero_suivi VARCHAR(255) DEFAULT NULL, statut VARCHAR(64) DEFAULT NULL, etiquette LONGTEXT DEFAULT NULL, INDEX IDX_COLIS_COMMANDE (commande_id), INDEX IDX_COLIS_EMBALLAGE (emballage_id), UNIQUE INDEX UNIQ_COLIS_POSITION (commande_id, position), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE colis_commande ADD CONSTRAINT FK_COLIS_COMMANDE FOREIGN KEY (commande_id) REFERENCES `order` (id)');
        $this->addSql('ALTER TABLE colis_commande ADD CONSTRAINT FK_COLIS_EMBALLAGE FOREIGN KEY (emballage_id) REFERENCES emballage (id)');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Suppression de données historiques interdite sans autorisation explicite.'); }
}
