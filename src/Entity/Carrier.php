<?php

namespace App\Entity;

use App\Repository\CarrierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CarrierRepository::class)]
class Carrier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?float $price = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\Range(min: 0, max: 100)]
    private float $tva = 0;
    public function getTva(): float { return $this->tva; }
    public function setTva(float $tva): static { $this->tva = $tva; return $this; }

    public function __toString()
    {
        $price = number_format($this->getPrice(), '2', decimal_separator: ','). ' €';
        return $this->getName().' - '.$price.' - '.$this->getDescription();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }
}
