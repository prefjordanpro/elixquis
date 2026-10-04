<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cancellation_request')]
#[ORM\Index(name: 'IDX_CANCELLATION_ORDER', columns: ['order_id'])]
#[ORM\Index(name: 'IDX_CANCELLATION_ADMIN', columns: ['resolved_by_id'])]
class CancellationRequest
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'cancellationRequests')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false)]
    private Order $order;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason;

    #[ORM\Column(length: 16)]
    private string $decision = 'pending';

    #[ORM\Column]
    private int $previousState;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    public function __construct(Order $order, ?string $reason, int $previousState)
    {
        $this->order = $order; $this->reason = $reason; $this->previousState = $previousState;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getReason(): ?string { return $this->reason; }
    public function getPreviousState(): int { return $this->previousState; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function getDecision(): string { return $this->decision; }
    public function getResolvedBy(): ?User { return $this->resolvedBy; }

    public function resolve(string $decision, ?User $admin = null): void
    {
        if ($this->decision !== 'pending') { return; }
        if (!in_array($decision, ['accepted', 'refused'], true)) { throw new \InvalidArgumentException('Décision invalide.'); }
        $this->decision = $decision; $this->resolvedAt = new \DateTimeImmutable(); $this->resolvedBy = $admin;
    }
}
