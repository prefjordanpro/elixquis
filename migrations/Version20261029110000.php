<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\LegalMarkup;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compléter et publier les mentions légales et la confidentialité dans les documents existants, sans modifier les CGV.';
    }

    public function up(Schema $schema): void
    {
        $markup = new LegalMarkup();
        $date = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $timestamp = $date->format('Y-m-d H:i:s');
        foreach (['mentions' => 'mentions-initial-20261029.html', 'confidentialite' => 'confidentialite-auditee-20261029.html'] as $type => $file) {
            $page = $this->connection->fetchAssociative('SELECT * FROM legal_page WHERE id = ?', [$type]);
            $this->abortIf(!$page, 'Les emplacements des documents légaux doivent déjà exister.');
            if ($page['published_version_id'] !== null) continue;
            $source = $markup->clean(file_get_contents(__DIR__.'/data/'.$file));
            $this->abortIf(trim($page['draft_content']) !== '' && $page['draft_content'] !== $source, 'Un brouillon personnalisé existe déjà : le conserver et le publier depuis le back-office.');
            $content = str_replace('[DATE DE PUBLICATION]', $date->format('d/m/Y'), $source);
            $this->abortIf(!$markup->isPublishable($content, $type) || !$markup->isComplete($page['draft_title']), 'Document incomplet : publication refusée.');
            $number = 1 + (int) $this->connection->fetchOne('SELECT COALESCE(MAX(number), 0) FROM legal_version WHERE page_id = ?', [$type]);
            $this->addSql('UPDATE legal_page SET draft_content = ?, updated_at = ?, revision = revision + 1 WHERE id = ?', [$source, $timestamp, $type]);
            $this->addSql('INSERT INTO legal_version (page_id, number, title, content, source_content, created_at, updated_at, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $type, $number, $page['draft_title'], $content, $source, $page['created_at'], $timestamp, $timestamp,
            ]);
            $this->addSql('UPDATE legal_page SET published_version_id = (SELECT id FROM legal_version WHERE page_id = ? AND number = ?), revision = revision + 1 WHERE id = ? AND published_version_id IS NULL', [$type, $number, $type]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les documents publiés et leur historique.');
    }
}
