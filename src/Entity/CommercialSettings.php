<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class CommercialSettings
{
    #[ORM\Id, ORM\Column]
    private int $id = 1;
    #[ORM\Column(options: ['default' => 5]), Assert\Range(min: 1, max: 1000000)]
    private int $lowStockThreshold = 5;
    #[ORM\Column(length: 20, options: ['default' => 'disabled']), Assert\Choice(choices: ['disabled', 'all', 'threshold'])]
    private string $freeShippingMode = 'disabled';
    #[ORM\Column(options: ['default' => 0]), Assert\Range(min: 0, max: 100000000)]
    private int $freeShippingThresholdCents = 0;
    public function getId(): int { return $this->id; }
    public function getLowStockThreshold(): int { return $this->lowStockThreshold; }
    public function setLowStockThreshold(int $value): static { $this->lowStockThreshold = $value; return $this; }
    public function getFreeShippingMode(): string { return $this->freeShippingMode; }
    public function setFreeShippingMode(string $value): static { $this->freeShippingMode = $value; return $this; }
    public function getFreeShippingThresholdCents(): int { return $this->freeShippingThresholdCents; }
    public function setFreeShippingThresholdCents(int $value): static { $this->freeShippingThresholdCents = $value; return $this; }
}
