<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class LegalPage
{
    public const LABELS = ['cgv' => 'Conditions Générales de Vente', 'mentions' => 'Mentions légales', 'confidentialite' => 'Politique de confidentialité', 'cookies' => 'Politique relative aux cookies'];
    #[ORM\Id, ORM\Column(length: 30)]
    private string $id;
    #[ORM\Column(length: 255)]
    private string $draftTitle;
    #[ORM\Column(type: 'text')]
    private string $draftContent = '';
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
    #[ORM\Version, ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $revision = 1;
    #[ORM\OneToOne(targetEntity: LegalVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?LegalVersion $publishedVersion = null;

    public function __construct(string $id, string $content = '')
    {
        if (!isset(self::LABELS[$id])) throw new \InvalidArgumentException('Document inconnu.');
        $this->id = $id; $this->draftTitle = $id === 'cgv' ? 'CONDITIONS GÉNÉRALES DE VENTE' : self::LABELS[$id];
        $this->draftContent = $content; $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }
    public function getId(): string { return $this->id; }
    public function getLabel(): string { return self::LABELS[$this->id]; }
    public function getDraftTitle(): string { return $this->draftTitle; }
    public function getDraftContent(): string { return $this->draftContent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getRevision(): int { return $this->revision; }
    public function getPublishedVersion(): ?LegalVersion { return $this->publishedVersion; }
    public function hasChanges(): bool
    {
        return !$this->publishedVersion || $this->draftTitle !== $this->publishedVersion->getTitle() || $this->draftContent !== $this->publishedVersion->getSourceContent();
    }
    public function saveDraft(string $title, string $content): void
    {
        $this->draftTitle = $title; $this->draftContent = $content; $this->updatedAt = new \DateTimeImmutable();
    }
    public function publish(LegalVersion $version): void
    {
        if ($version->getPage() !== $this) throw new \LogicException('Version et document incompatibles.');
        $this->publishedVersion = $version;
    }
}
