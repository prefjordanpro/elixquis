<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[\Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity(fields: ['slug'], errorPath: 'name', message: 'Ce nom est déjà utilisé. Choisissez un nom distinct pour le nouveau produit.')]
class Product
{
    public const PROMOTION_PERCENTAGES = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90];
    #[ORM\Column(nullable: true), Assert\Choice(choices: self::PROMOTION_PERCENTAGES)]
    private ?int $promotionPercent = null;
    public function getPromotionPercent(): ?int { return $this->promotionPercent; }
    public function setPromotionPercent(?int $value): static
    {
        $this->promotionPercent = $value; $this->promotionEnabled = $value !== null; $this->promotionPriceCents = null;
        return $this;
    }
    /** L'option historique évite de réécrire une ancienne promotion à prix fixe à l'ouverture du formulaire. */
    public function getPromotionSelection(): ?int { return $this->promotionPercent ?? ($this->promotionEnabled && $this->promotionPriceCents !== null ? -1 : null); }
    public function setPromotionSelection(?int $value): static { return $value === -1 ? $this : $this->setPromotionPercent($value); }
    private function configuredPromotionCents(): ?int
    {
        if ($this->promotionPercent !== null) {
            if (!in_array($this->promotionPercent, self::PROMOTION_PERCENTAGES, true)) { return null; }
            return (int) round((int) round($this->getRegularPriceWt() * 100) * (100 - $this->promotionPercent) / 100);
        }
        return $this->promotionPriceCents;
    }
    public function getPromotionDiscountPercent(): ?int
    {
        if (!$this->isPromotionActive()) { return null; }
        return $this->promotionPercent ?? (int) round(100 * (1 - $this->configuredPromotionCents() / (int) round($this->getRegularPriceWt() * 100)));
    }
    public function getPromotionSummary(): string
    {
        if (!$this->promotionEnabled || $this->configuredPromotionCents() === null) { return 'Aucune'; }
        $label = $this->promotionPercent !== null ? '−'.$this->promotionPercent.' %' : 'Prix fixe historique';
        $now = new \DateTimeImmutable();
        if ($this->promotionStartsAt && $this->promotionStartsAt > $now) { return $label.' · À venir'; }
        if ($this->promotionEndsAt && $this->promotionEndsAt <= $now) { return $label.' · Expirée'; }
        return $label.($this->isPromotionActive($now) ? ' · Active' : ' · Inactive');
    }
    #[ORM\Column(options: ['default' => false])]
    private bool $promotionEnabled = false;
    #[ORM\Column(nullable: true), Assert\Range(min: 1, max: 100000000)]
    private ?int $promotionPriceCents = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $promotionStartsAt = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $promotionEndsAt = null;
    public function isPromotionEnabled(): bool { return $this->promotionEnabled; }
    public function setPromotionEnabled(bool $value): static { $this->promotionEnabled = $value; return $this; }
    public function getPromotionPriceCents(): ?int { return $this->promotionPriceCents; }
    public function setPromotionPriceCents(?int $value): static { $this->promotionPriceCents = $value; return $this; }
    public function getPromotionStartsAt(): ?\DateTimeImmutable { return $this->promotionStartsAt; }
    public function setPromotionStartsAt(?\DateTimeImmutable $value): static { $this->promotionStartsAt = $value; return $this; }
    public function getPromotionEndsAt(): ?\DateTimeImmutable { return $this->promotionEndsAt; }
    public function setPromotionEndsAt(?\DateTimeImmutable $value): static { $this->promotionEndsAt = $value; return $this; }
    public function isPromotionActive(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $cents = $this->configuredPromotionCents();
        return $this->promotionEnabled && $cents > 0
            && $cents < (int) round($this->getRegularPriceWt() * 100)
            && (!$this->promotionStartsAt || $this->promotionStartsAt <= $now)
            && (!$this->promotionEndsAt || $now < $this->promotionEndsAt);
    }
    #[Assert\Callback]
    public function validatePromotion(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        $cents = $this->configuredPromotionCents();
        if (($this->promotionEnabled && !$cents) || ($cents !== null && $cents >= (int) round($this->getRegularPriceWt() * 100))) {
            $context->buildViolation('Le prix promotionnel TTC doit être positif et inférieur au prix normal TTC.')->atPath($this->promotionPercent !== null ? 'promotionSelection' : 'promotionPriceCents')->addViolation();
        }
        if ($this->promotionStartsAt && $this->promotionEndsAt && $this->promotionEndsAt <= $this->promotionStartsAt) {
            $context->buildViolation('La fin doit suivre le début.')->atPath('promotionEndsAt')->addViolation();
        }
    }
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
    public function getStockLevel(): int { return $this->stock; }
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
        if (!$this->slug) { $this->slug = (new \Symfony\Component\String\Slugger\AsciiSlugger('fr'))->slug($name)->lower()->toString(); }

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

    public function getPriceWt(?\DateTimeImmutable $now = null)
    {
        return $this->isPromotionActive($now) ? $this->configuredPromotionCents() / 100 : $this->getRegularPriceWt();
    }

    public function getRegularPriceWt(): float
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
