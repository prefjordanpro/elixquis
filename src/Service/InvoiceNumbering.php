<?php
declare(strict_types=1);
namespace App\Service;
use Doctrine\DBAL\Connection;

final class InvoiceNumbering
{
    public function __construct(private Connection $db) {}
    public function next(string $type): array
    {
        if (!in_array($type, ['invoice', 'credit'], true)) throw new \InvalidArgumentException('Type de document inconnu.');
        $db = $this->db;
        if (!$db->isTransactionActive()) throw new \LogicException('La numérotation exige une transaction.');
        $sqlite = $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;
        // Also works on fresh SQLite test schemas; the migration seeds this row in production.
        $db->executeStatement(($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE').' INTO invoice_sequence (id, year, invoice_value, credit_value) VALUES (1, 0, 0, 0)');
        $row = $db->fetchAssociative('SELECT * FROM invoice_sequence WHERE id = 1'.($sqlite ? '' : ' FOR UPDATE'));
        $date = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        if ($row['last_issued_at'] && $date < new \DateTimeImmutable($row['last_issued_at'], new \DateTimeZone('Europe/Paris'))) $date = new \DateTimeImmutable($row['last_issued_at'], new \DateTimeZone('Europe/Paris'));
        $year = (int) $date->format('Y');
        $invoice = $year === (int) $row['year'] ? (int) $row['invoice_value'] : 0;
        $credit = $year === (int) $row['year'] ? (int) $row['credit_value'] : 0;
        $value = $type === 'invoice' ? ++$invoice : ++$credit;
        $db->update('invoice_sequence', ['year'=>$year, 'invoice_value'=>$invoice, 'credit_value'=>$credit, 'last_issued_at'=>$date->format('Y-m-d H:i:s')], ['id'=>1]);
        return [sprintf('%s-%d-%06d', $type === 'invoice' ? 'FAC' : 'AV', $year, $value), $date];
    }
}
