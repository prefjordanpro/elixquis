<?php
declare(strict_types=1);
namespace App\Shipping;

/** Uniquement construit à partir des réponses API validées côté serveur. */
final readonly class DeliverySelection
{
    public function __construct(public array $snapshot, public int $priceCents, public float $taxRate) {}
    public function carrier(): \App\Entity\Carrier
    {
        return (new \App\Entity\Carrier())->setName($this->snapshot['carrier_name'])->setDescription($this->snapshot['method_name'])
            ->setPrice($this->priceCents / 100)->setTva($this->taxRate);
    }
}
