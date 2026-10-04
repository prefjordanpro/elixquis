<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ORM\UniqueConstraint(name: 'UNIQ_ORDER_PAYMENT_INTENT', columns: ['stripe_payment_intent_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_ORDER_REFUND', columns: ['stripe_refund_id'])]
class Order
{
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shippingSnapshot = null;
    public function getShippingSnapshot(): ?array { return $this->shippingSnapshot; }
    public function setShippingSnapshot(?array $snapshot): static { $this->shippingSnapshot = $snapshot; return $this; }

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: Shipment::class)]
    private ?Shipment $shipment = null;
    public function getShipment(): ?Shipment { return $this->shipment; }
    public function setShipment(Shipment $shipment): void { $this->shipment = $shipment; }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(length: 255)]
    private ?string $carrierName = null;

    #[ORM\Column]
    private ?float $carrierPrice = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $delivery = null;

    #[ORM\Column]
    private ?int $state = null;

    /**
     * @var Collection<int, OrderDetail>
     */
    #[ORM\OneToMany(targetEntity: OrderDetail::class, mappedBy: 'myOrder', orphanRemoval: true, cascade:['persist'])]
    private Collection $orderDetails;

    /** @var Collection<int, CancellationRequest> */
    #[ORM\OneToMany(targetEntity: CancellationRequest::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $cancellationRequests;

    public function getCancellationRequests(): Collection { return $this->cancellationRequests; }
    public function getLatestCancellationRequest(): ?CancellationRequest { return $this->cancellationRequests->last() ?: null; }
    public function addCancellationRequest(CancellationRequest $request): void { $this->cancellationRequests->add($request); }
    public function canRequestCancellation(): bool { return in_array($this->state, [0, 1, 2], true) && !$this->stripeRefundId && !$this->shipment?->isActive(); }

    #[ORM\ManyToOne(inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $stripe_session_id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeRefundId = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $stripeRefundStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $stateBeforeRefund = null;

    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(?string $id): static { $this->stripePaymentIntentId = $id; return $this; }
    public function getStripeRefundId(): ?string { return $this->stripeRefundId; }
    public function setStripeRefundId(?string $id): static { $this->stripeRefundId = $id; return $this; }
    public function getStripeRefundStatus(): ?string { return $this->stripeRefundStatus; }
    public function setStripeRefundStatus(?string $status): static { $this->stripeRefundStatus = $status; return $this; }
    public function getStateBeforeRefund(): ?int { return $this->stateBeforeRefund; }
    public function setStateBeforeRefund(?int $state): static { $this->stateBeforeRefund = $state; return $this; }

    #[ORM\Column(options: ['default' => false])]
    private bool $stockReserved = false;

    #[ORM\Column(options: ['default' => 0])]
    private float $carrierTvaRate = 0;
    public function getCarrierTvaRate(): float { return $this->carrierTvaRate; }
    public function setCarrierTvaRate(float $rate): static { $this->carrierTvaRate = $rate; return $this; }

    public function getTotalCents(): int
    {
        $total = (int) round($this->carrierPrice * 100);
        foreach ($this->orderDetails as $line) {
            $total += (int) round($line->getProductPrice() * 100) * $line->getProductQuantity();
        }
        return $total;
    }

    public function isStockReserved(): bool { return $this->stockReserved; }
    public function setStockReserved(bool $reserved): static { $this->stockReserved = $reserved; return $this; }
    public function getReference(): string { return 'CMD-'.$this->createdAt?->format('Ymd').'-'.$this->id; }

    public function __construct()
    {
        $this->orderDetails = new ArrayCollection();
        $this->cancellationRequests = new ArrayCollection();
    }

    public function getTotalWt(): float
    {
        return $this->getTotalCents() / 100;
    }


    public function getTotalTva(): float
    {
        $cents = 0;
        foreach ($this->orderDetails as $line) { $cents += $line->getTotalTvaCents(); }
        $delivery = (int) round($this->carrierPrice * 100);
        $cents += $delivery - (int) round($delivery / (1 + $this->carrierTvaRate / 100));
        return $cents / 100;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCarrierName(): ?string
    {
        return $this->carrierName;
    }

    public function setCarrierName(string $carrierName): static
    {
        $this->carrierName = $carrierName;

        return $this;
    }

    public function getCarrierPrice(): ?float
    {
        return $this->carrierPrice;
    }

    public function setCarrierPrice(float $carrierPrice): static
    {
        $this->carrierPrice = $carrierPrice;

        return $this;
    }

    public function getDelivery(): ?string
    {
        return $this->delivery;
    }

    public function setDelivery(string $delivery): static
    {
        $this->delivery = $delivery;

        return $this;
    }

    public function getState(): ?int
    {
        return $this->state;
    }

    public function setState(int $state): static
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return Collection<int, OrderDetail>
     */
    public function getOrderDetails(): Collection
    {
        return $this->orderDetails;
    }

    public function addOrderDetail(OrderDetail $orderDetail): static
    {
        if (!$this->orderDetails->contains($orderDetail)) {
            $this->orderDetails->add($orderDetail);
            $orderDetail->setMyOrder($this);
        }

        return $this;
    }

    public function removeOrderDetail(OrderDetail $orderDetail): static
    {
        if ($this->orderDetails->removeElement($orderDetail)) {
            // set the owning side to null (unless already changed)
            if ($orderDetail->getMyOrder() === $this) {
                $orderDetail->setMyOrder(null);
            }
        }

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getStripeSessionId(): ?string
    {
        return $this->stripe_session_id;
    }

    public function setStripeSessionId(?string $stripe_session_id): static
    {
        $this->stripe_session_id = $stripe_session_id;

        return $this;
    }
}
