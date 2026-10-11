<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Vérification email et tokens espace client à usage unique, stockés sous forme de hash.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE account_token (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(16) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', identity_hash VARCHAR(64) NOT NULL, INDEX IDX_69B8784AA76ED395 (user_id), UNIQUE INDEX UNIQ_69B8784AB3BC57DA (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE account_token ADD CONSTRAINT FK_ACCOUNT_TOKEN_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_token');
        $this->addSql('ALTER TABLE `user` DROP email_verified_at');
    }
}
