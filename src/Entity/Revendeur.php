<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Revendeur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    public function getId(): ?int { return $this->id; }
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $nom = '';
    public function getNom(): string { return $this->nom; }
    public function setNom(string $value): static { $this->nom = $value; return $this; }
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $adresse = '';
    public function getAdresse(): string { return $this->adresse; }
    public function setAdresse(string $value): static { $this->adresse = $value; return $this; }
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $complementAdresse = null;
    public function getComplementAdresse(): ?string { return $this->complementAdresse; }
    public function setComplementAdresse(?string $value): static { $this->complementAdresse = $value; return $this; }
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $codePostal = '';
    public function getCodePostal(): string { return $this->codePostal; }
    public function setCodePostal(string $value): static { $this->codePostal = $value; return $this; }
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $ville = '';
    public function getVille(): string { return $this->ville; }
    public function setVille(string $value): static { $this->ville = $value; return $this; }
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $pays = 'France';
    public function getPays(): string { return $this->pays; }
    public function setPays(string $value): static { $this->pays = $value; return $this; }
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    private ?float $latitude = null;
    public function getLatitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $value): static { $this->latitude = $value; return $this; }
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    private ?float $longitude = null;
    public function getLongitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $value): static { $this->longitude = $value; return $this; }
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $telephone = null;
    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $value): static { $this->telephone = $value; return $this; }
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Email]
    private ?string $email = null;
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $value): static { $this->email = $value; return $this; }
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    private ?string $siteWeb = null;
    public function getSiteWeb(): ?string { return $this->siteWeb; }
    public function setSiteWeb(?string $value): static { $this->siteWeb = $value; return $this; }
    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $value): static { $this->actif = $value; return $this; }
    #[ORM\Column(type: 'integer')]
    private int $priorite = 0;
    public function getPriorite(): int { return $this->priorite; }
    public function setPriorite(int $value): static { $this->priorite = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $value): static { $this->createdAt = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $value): static { $this->updatedAt = $value; return $this; }
    public function __construct() { $this->createdAt = $this->updatedAt = new \DateTimeImmutable(); }
    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
    public function addressFingerprint(): string
    {
        return hash('sha256', implode('|', [$this->adresse, $this->codePostal, $this->ville, $this->pays]));
    }
    #[Assert\Callback]
    public function validateCoordinates(ExecutionContextInterface $context): void
    {
        if (($this->latitude === null) !== ($this->longitude === null)) {
            $context->buildViolation('Renseignez les deux coordonnées ou laissez les deux vides.')->atPath('latitude')->addViolation();
        }
    }
}

