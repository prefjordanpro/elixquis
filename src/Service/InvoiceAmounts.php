<?php
declare(strict_types=1);
namespace App\Service;

/** Only the conversion boundary reads the legacy float fields. All accounting operations are integer. */
final class InvoiceAmounts
{
    public static function decimal(float $value): int
    {
        if (!is_finite($value) || $value < 0 || $value > 1000000000) throw new \DomainException('Montant comptable invalide.');
        return (int) str_replace('.', '', number_format($value, 2, '.', ''));
    }
    public static function net(int $gross, int $rateBasisPoints): int
    {
        if ($gross < 0 || $rateBasisPoints < 0 || $rateBasisPoints > 10000) throw new \DomainException('Montant ou taux de TVA invalide.');
        $denominator = 10000 + $rateBasisPoints;
        return intdiv($gross * 10000 + intdiv($denominator, 2), $denominator);
    }
}
