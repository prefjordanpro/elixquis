<?php
declare(strict_types=1);
namespace App\Service;

final class ShippingConfiguration
{
    public readonly ?float $taxRate;
    public function __construct(public readonly bool $enabled, private array $sender, private array $parcel,
        float|string|null $taxRate, public readonly bool $quoteIncludesTax,
        public readonly bool $legacyFallbackEnabled = true)
    {
        $this->taxRate = is_numeric($taxRate) ? (float) $taxRate : null;
        foreach (['packaging_weight_g', 'max_units'] as $field) {
            if (is_string($this->parcel[$field] ?? null) && preg_match('/^\d+$/D', $this->parcel[$field])) {
                $this->parcel[$field] = (int) $this->parcel[$field];
            }
        }
    }

    public function sender(): array
    {
        foreach (['name', 'address_line_1', 'postal_code', 'city', 'country_code'] as $field) {
            if (!is_string($this->sender[$field] ?? null) || trim($this->sender[$field]) === '') {
                throw new \App\Shipping\ShippingConfigurationException('Configuration incomplète : l’adresse d’expédition Sendcloud doit être configurée.');
            }
        }
        if (!preg_match('/^[A-Z]{2}$/', $this->sender['country_code'])) { throw new \App\Shipping\ShippingConfigurationException('Configuration incomplète : pays d’expédition invalide.'); }
        return $this->sender;
    }

    public function parcel(array $lines): array
    {
        if (!$lines) { throw new \DomainException('Votre panier est vide.'); }
        foreach (['length_cm', 'width_cm', 'height_cm', 'max_units'] as $field) {
            if (!is_numeric($this->parcel[$field] ?? null) || $this->parcel[$field] <= 0) {
                throw new \App\Shipping\ShippingConfigurationException('Configuration incomplète : les dimensions et la capacité du colis doivent être configurées.');
            }
        }
        if (!is_int($this->parcel['packaging_weight_g'] ?? null) || $this->parcel['packaging_weight_g'] < 0) {
            throw new \App\Shipping\ShippingConfigurationException('Configuration incomplète : le poids de l’emballage doit être configuré.');
        }
        $weight = $this->parcel['packaging_weight_g']; $units = 0;
        foreach ($lines as $line) {
            if (!$line['object']->getShippingWeightGrams() || !is_int($line['qty']) || $line['qty'] < 1) {
                throw new \App\Shipping\ShippingConfigurationException('Configuration incomplète : le poids d’un produit doit être renseigné avant de proposer la livraison.');
            }
            $units += $line['qty']; $weight += $line['object']->getShippingWeightGrams() * $line['qty'];
        }
        if ($units > $this->parcel['max_units']) { throw new \DomainException('Cette sélection dépasse la capacité du colis. Contactez-nous pour sa livraison.'); }
        return ['weight' => ['value' => number_format($weight / 1000, 3, '.', ''), 'unit' => 'kg'],
            'dimensions' => ['length' => (string) $this->parcel['length_cm'], 'width' => (string) $this->parcel['width_cm'], 'height' => (string) $this->parcel['height_cm'], 'unit' => 'cm']];
    }

    public function sellingPrice(string $amount): int
    {
        if ($this->taxRate === null || $this->taxRate < 0 || $this->taxRate > 100 || !preg_match('/^\d{1,6}(?:\.\d{1,4})?$/D', $amount)) {
            throw new \App\Shipping\ShippingConfigurationException('Configuration incomplète ou invalide : la politique tarifaire de livraison doit être configurée.');
        }
        return (int) round((float) $amount * 100 * ($this->quoteIncludesTax ? 1 : (1 + $this->taxRate / 100)));
    }
}
