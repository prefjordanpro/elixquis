<?php
declare(strict_types=1);
namespace App\Tests;
use App\Service\InvoiceNumbering;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class InvoiceNumberingTest extends TestCase
{
    private function database(string $path = ':memory:'): \Doctrine\DBAL\Connection
    {
        $db = DriverManager::getConnection(['driver'=>'pdo_sqlite', 'path'=>$path]);
        $db->executeStatement('CREATE TABLE invoice_sequence (id INTEGER PRIMARY KEY, year INTEGER NOT NULL, invoice_value INTEGER NOT NULL, credit_value INTEGER NOT NULL, last_issued_at DATETIME DEFAULT NULL)');
        return $db;
    }
    public function testRollbackDoesNotConsumeANumberAndSeriesRemainIndependent(): void
    {
        $db = $this->database(); $allocator = new InvoiceNumbering($db);
        $db->beginTransaction(); $rolledBack = $allocator->next('invoice')[0]; $db->rollBack();
        $db->beginTransaction(); self::assertSame($rolledBack, $allocator->next('invoice')[0]);
        self::assertSame('AV-'.date('Y').'-000001', $allocator->next('credit')[0]); $db->commit();
        $db->beginTransaction(); self::assertSame('FAC-'.date('Y').'-000002', $allocator->next('invoice')[0]); $db->commit();
        $db->close();
    }
    public function testYearRolloverAndClockRegressionDoNotBreakChronology(): void
    {
        $db = $this->database(); $year = (int) date('Y');
        $db->insert('invoice_sequence', ['id'=>1, 'year'=>$year-1, 'invoice_value'=>120, 'credit_value'=>12]);
        $allocator = new InvoiceNumbering($db); $db->beginTransaction(); self::assertSame('FAC-'.$year.'-000001', $allocator->next('invoice')[0]); $db->commit();
        $db->update('invoice_sequence', ['last_issued_at'=>($year+1).'-01-01 00:00:00', 'year'=>$year+1, 'invoice_value'=>5], ['id'=>1]);
        $db->beginTransaction(); [$number, $date] = $allocator->next('invoice'); $db->commit();
        self::assertSame('FAC-'.($year+1).'-000006', $number); self::assertSame(($year+1).'-01-01', $date->format('Y-m-d')); $db->close();
    }
    public function testTwoConcurrentTransactionsReceiveDistinctConsecutiveNumbers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'elixquis-invoice-');
        $db = $this->database($path); $db->close();
        $processes = []; $start = (string) (microtime(true) + 1);
        try {
            for ($i=0; $i<2; ++$i) {
                $pipes = []; $process = proc_open([PHP_BINARY, __DIR__.'/Support/InvoiceNumberingWorker.php', $path, $start], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
                self::assertIsResource($process); fclose($pipes[0]); $processes[] = [$process, $pipes];
            }
            $numbers = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error); $numbers[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['number'];
            }
            sort($numbers); self::assertSame(['FAC-'.date('Y').'-000001', 'FAC-'.date('Y').'-000002'], $numbers);
        } finally { if (is_file($path)) unlink($path); }
    }
}
