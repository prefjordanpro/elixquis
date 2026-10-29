<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\LegalMarkup;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publier les CGV déjà préparées, sans recréer le document ni remplacer une version publiée.';
    }

    public function up(Schema $schema): void
    {
        $page = $this->connection->fetchAssociative("SELECT * FROM legal_page WHERE id = 'cgv'");
        $this->abortIf(!$page, 'Les CGV préparées doivent exister avant leur publication.');
        if ($page['published_version_id'] !== null) return;

        $markup = new LegalMarkup();
        $date = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $content = str_replace('[DATE DE PUBLICATION]', $date->format('d/m/Y'), $markup->clean($page['draft_content']));
        $this->abortIf(!$markup->isPublishable($content, 'cgv') || !$markup->isComplete($page['draft_title']), 'Le brouillon des CGV ne peut pas être publié en l’état.');
        $number = 1 + (int) $this->connection->fetchOne("SELECT COALESCE(MAX(number), 0) FROM legal_version WHERE page_id = 'cgv'");
        $this->addSql('INSERT INTO legal_version (page_id, number, title, content, source_content, created_at, updated_at, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            'cgv', $number, $page['draft_title'], $content, $page['draft_content'], $page['created_at'], $page['updated_at'], $date->format('Y-m-d H:i:s'),
        ]);
        $this->addSql("UPDATE legal_page SET published_version_id = (SELECT id FROM legal_version WHERE page_id = 'cgv' AND number = ?), revision = revision + 1 WHERE id = 'cgv' AND published_version_id IS NULL", [$number]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver la version contractuelle publiée et ses éventuelles acceptations.');
    }
}
