<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity, UniqueEntity(fields: ['code'])]
class PromoCode
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 50, unique: true), Assert\NotBlank, Assert\Length(max: 50), Assert\Regex('/^[A-Z0-9_-]+$/D')]
    private string $code = '';
    #[ORM\Column(length: 150), Assert\NotBlank, Assert\Length(max: 150)]
    private string $name = '';
    #[ORM\Column(options: ['default' => false])]
    private bool $active = false;
    #[ORM\Column(length: 10), Assert\Choice(choices: ['percent', 'fixed'])]
    private string $type = 'percent';
    #[ORM\Column, Assert\Range(min: 1, max: 100000000)]
    private int $value = 10;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;
    #[ORM\Column(nullable: true), Assert\Range(min: 0, max: 100000000)]
    private ?int $minimumCents = null;
    #[ORM\Column(nullable: true), Assert\Positive]
    private ?int $usageLimit = null;
    #[ORM\Column(options: ['default' => 0])]
    private int $uses = 0;
    #[ORM\Column(options: ['default' => false])]
    private bool $stackable = false;
    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $value): static { $this->code = strtoupper(trim($value)); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $value): static { $this->name = $value; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $value): static { $this->active = $value; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $value): static { $this->type = $value; return $this; }
    public function getValue(): int { return $this->value; }
    public function setValue(int $value): static { $this->value = $value; return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $value): static { $this->startsAt = $value; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $value): static { $this->endsAt = $value; return $this; }
    public function getMinimumCents(): ?int { return $this->minimumCents; }
    public function setMinimumCents(?int $value): static { $this->minimumCents = $value; return $this; }
    public function getUsageLimit(): ?int { return $this->usageLimit; }
    public function setUsageLimit(?int $value): static { $this->usageLimit = $value; return $this; }
    public function getUses(): int { return $this->uses; }
    public function recordConfirmedUse(): void { ++$this->uses; }
    public function isStackable(): bool { return $this->stackable; }
    public function setStackable(bool $value): static { $this->stackable = $value; return $this; }
    public function isAvailable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return $this->active && ($this->startsAt === null || $this->startsAt <= $now)
            && ($this->endsAt === null || $now < $this->endsAt)
            && ($this->usageLimit === null || $this->uses < $this->usageLimit);
    }
    #[Assert\Callback]
    public function validate(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->type === 'percent' && $this->value > 100) { $context->buildViolation('Le pourcentage doit être compris entre 1 et 100.')->atPath('value')->addViolation(); }
        if ($this->startsAt && $this->endsAt && $this->endsAt <= $this->startsAt) { $context->buildViolation('La fin doit suivre le début.')->atPath('endsAt')->addViolation(); }
    }
}
