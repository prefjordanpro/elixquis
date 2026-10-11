<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

/** Durable receipt and immutable email snapshot, committed with the business transition. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_ORDER_NOTIFICATION_STATE', columns: ['order_id', 'state'])]
class OrderNotification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'notifications')]
    #[ORM\JoinColumn(nullable: false)]
    private Order $order;
    #[ORM\Column]
    private int $state;
    #[ORM\Column(type: Types::JSON)]
    private array $payload;
    #[ORM\Column(length: 16)]
    private string $status = 'pending';
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
    public function __construct(Order $order, int $state, array $payload)
    {
        $this->order = $order; $this->state = $state; $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getState(): int { return $this->state; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
