<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$db = \Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite', 'path'=>$argv[1]]);
$db->executeStatement('PRAGMA busy_timeout = 10000');
while (microtime(true) < (float) $argv[2]) usleep(1000);
$db->beginTransaction();
try {
    [$number, $date] = (new \App\Service\InvoiceNumbering($db))->next('invoice');
    usleep(100000); // Deliberately overlap the two allocation transactions.
    $db->commit();
    echo json_encode(['number'=>$number, 'issuedAt'=>$date->format(DATE_ATOM)], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    $db->rollBack(); fwrite(STDERR, $error->getMessage()); exit(1);
}
