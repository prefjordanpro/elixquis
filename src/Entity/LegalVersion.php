<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Published snapshots have no edit/delete operations; their content is immutable. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_LEGAL_PAGE_NUMBER', columns: ['page_id', 'number'])]
class LegalVersion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: LegalPage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private LegalPage $page;
    #[ORM\Column]
    private int $number;
    #[ORM\Column(length: 255)]
    private string $title;
    #[ORM\Column(type: 'text')]
    private string $content;
    #[ORM\Column(type: 'text')]
    private string $sourceContent;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $publishedAt;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $publishedBy;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisherLabel;

    public function __construct(LegalPage $page, int $number, string $content, ?User $publisher = null, ?\DateTimeImmutable $publishedAt = null)
    {
        $this->page = $page; $this->number = $number; $this->title = $page->getDraftTitle();
        $this->sourceContent = $page->getDraftContent(); $this->content = $content;
        $this->createdAt = $page->getCreatedAt(); $this->updatedAt = $page->getUpdatedAt();
        $this->publishedAt = $publishedAt ?? new \DateTimeImmutable(); $this->publishedBy = $publisher;
        $this->publisherLabel = $publisher ? (string) $publisher : null;
    }
    public function getId(): ?int { return $this->id; }
    public function getPage(): LegalPage { return $this->page; }
    public function getNumber(): int { return $this->number; }
    public function getTitle(): string { return $this->title; }
    public function getContent(): string { return $this->content; }
    public function getSourceContent(): string { return $this->sourceContent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    public function getPublishedBy(): ?User { return $this->publishedBy; }
    public function getPublisherLabel(): ?string { return $this->publisherLabel; }
    public function isCurrent(): bool { return $this->page->getPublishedVersion()?->getId() === $this->id; }
    public function getStatus(): string { return $this->isCurrent() ? 'Publiée' : 'Archivée'; }
}
