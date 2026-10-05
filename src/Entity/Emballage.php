<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: \App\Repository\EmballageRepository::class)]
class Emballage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 160), Assert\NotBlank, Assert\Length(max: 160)]
    private string $nom = '';
    #[ORM\Column, Assert\Positive]
    private int $capacite = 1;
    #[ORM\Column(nullable: true), Assert\Positive]
    private ?int $poidsVideGrammes = null;
    #[ORM\Column(nullable: true), Assert\Positive]
    private ?float $longueurCm = null;
    #[ORM\Column(nullable: true), Assert\Positive]
    private ?float $largeurCm = null;
    #[ORM\Column(nullable: true), Assert\Positive]
    private ?float $hauteurCm = null;
    #[ORM\Column(nullable: true), Assert\Positive]
    private ?int $poidsMaxGrammes = null;
    #[ORM\Column]
    private bool $actif = false;
    #[ORM\Column]
    private int $priorite = 0;

    public function getId(): ?int { return $this->id; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $value): static { $this->nom = trim($value); return $this; }
    public function getCapacite(): int { return $this->capacite; }
    public function setCapacite(int $value): static { $this->capacite = $value; return $this; }
    public function getPoidsVideGrammes(): ?int { return $this->poidsVideGrammes; }
    public function setPoidsVideGrammes(?int $value): static { $this->poidsVideGrammes = $value; return $this; }
    public function getLongueurCm(): ?float { return $this->longueurCm; }
    public function setLongueurCm(?float $value): static { $this->longueurCm = $value; return $this; }
    public function getLargeurCm(): ?float { return $this->largeurCm; }
    public function setLargeurCm(?float $value): static { $this->largeurCm = $value; return $this; }
    public function getHauteurCm(): ?float { return $this->hauteurCm; }
    public function setHauteurCm(?float $value): static { $this->hauteurCm = $value; return $this; }
    public function getPoidsMaxGrammes(): ?int { return $this->poidsMaxGrammes; }
    public function setPoidsMaxGrammes(?int $value): static { $this->poidsMaxGrammes = $value; return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $value): static { $this->actif = $value; return $this; }
    public function getPriorite(): int { return $this->priorite; }
    public function setPriorite(int $value): static { $this->priorite = $value; return $this; }
    public function getDimensions(): string { return implode(' × ', [$this->longueurCm ?? '—', $this->largeurCm ?? '—', $this->hauteurCm ?? '—']).' cm'; }
    public function __toString(): string { return $this->nom; }
    public function estComplet(): bool
    {
        return $this->nom !== '' && $this->capacite > 0 && ($this->poidsVideGrammes ?? 0) > 0
            && ($this->longueurCm ?? 0) > 0 && ($this->largeurCm ?? 0) > 0 && ($this->hauteurCm ?? 0) > 0
            && is_finite($this->longueurCm) && is_finite($this->largeurCm) && is_finite($this->hauteurCm)
            && ($this->poidsMaxGrammes === null || $this->poidsMaxGrammes > $this->poidsVideGrammes);
    }
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->actif && !$this->estComplet()) {
            $context->buildViolation('Complétez le poids réel avec protections et les dimensions avant activation.')->atPath('actif')->addViolation();
        }
    }
    public function snapshot(): array
    {
        return ['id' => $this->id, 'nom' => $this->nom, 'capacite' => $this->capacite, 'poids_emballage_g' => $this->poidsVideGrammes,
            'dimensions' => ['length' => (string) $this->longueurCm, 'width' => (string) $this->largeurCm, 'height' => (string) $this->hauteurCm, 'unit' => 'cm'],
            'poids_max_g' => $this->poidsMaxGrammes, 'priorite' => $this->priorite];
    }
}
