<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[\Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity(fields: ['slug'], message: 'Ce lien produit est déjà utilisé.')]
class Product
{
    #[ORM\Column(nullable: true)]
    #[\Symfony\Component\Validator\Constraints\Positive]
    private ?int $shippingWeightGrams = null;
    public function getShippingWeightGrams(): ?int { return $this->shippingWeightGrams; }
    public function setShippingWeightGrams(?int $grams): static { $this->shippingWeightGrams = $grams; return $this; }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    #[Assert\Length(max: 255)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Ajoutez une image au produit.')]
    private ?string $illustration = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?float $price = null;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private ?float $tva = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    private ?Category $category = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isHomepage = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Le stock ne peut pas être négatif.')]
    private int $stock = 0;

    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;
    public function getVersion(): int { return $this->version; }

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    public function getStock(): int { return $this->stock; }
    public function setStock(int $stock): static
    {
        $this->stock = $stock;
        return $this;
    }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): static { $this->isActive = $active; return $this; }

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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

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

    public function getIllustration(): ?string
    {
        return $this->illustration;
    }

    public function setIllustration(?string $illustration): static
    {
        $this->illustration = $illustration ?? $this->illustration;

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

    public function getPriceWt()
    {
        $coeff = 1 + ($this->tva/100);
        return round ($coeff * $this->price, 2);
    }

    public function getTva(): ?float
    {
        return $this->tva;
    }

    public function setTva(float $tva): static
    {
        $this->tva = $tva;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function isHomepage(): ?bool
    {
        return $this->isHomepage;
    }

    public function setIsHomepage(?bool $isHomepage): static
    {
        $this->isHomepage = $isHomepage;

        return $this;
    }
}
