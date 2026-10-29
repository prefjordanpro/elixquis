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
#[ORM\Index(name: 'IDX_ORDER_PROMO_CODE_DEFINITION', columns: ['promo_code_definition_id'])]
class Order
{
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $invoiceCustomerSnapshot = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paymentConfirmedAt = null;
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: Invoice::class)]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $invoices;
    public function getInvoices(): Collection { return $this->invoices; }
    public function addInvoice(Invoice $invoice): void
    {
        if ($invoice->getOrder() !== $this) throw new \LogicException('Document et commande incompatibles.');
        if (!$this->invoices->contains($invoice)) $this->invoices->add($invoice);
    }
    public function getInitialInvoice(): ?Invoice { foreach ($this->invoices as $invoice) if ($invoice->getType() === 'invoice') return $invoice; return null; }
    public function getInvoiceCustomerSnapshot(): ?array { return $this->invoiceCustomerSnapshot; }
    public function recordInvoiceCustomer(array $snapshot): void
    {
        if ($this->invoiceCustomerSnapshot !== null) throw new \LogicException('Les coordonnées de commande sont déjà enregistrées.');
        $this->invoiceCustomerSnapshot = $snapshot;
    }
    public function getPaymentConfirmedAt(): ?\DateTimeImmutable { return $this->paymentConfirmedAt; }
    public function recordPaymentConfirmation(): void { $this->paymentConfirmedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')); }
    #[ORM\ManyToOne(targetEntity: LegalVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?LegalVersion $acceptedTermsVersion = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;
    public function getAcceptedTermsVersion(): ?LegalVersion { return $this->acceptedTermsVersion; }
    public function getTermsAcceptedAt(): ?\DateTimeImmutable { return $this->termsAcceptedAt; }
    public function acceptTerms(LegalVersion $version): void
    {
        if ($this->acceptedTermsVersion !== null || $version->getPage()->getId() !== 'cgv') throw new \LogicException('La version de CGV acceptée ne peut pas être remplacée.');
        $this->acceptedTermsVersion = $version; $this->termsAcceptedAt = new \DateTimeImmutable();
    }
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PromoCode $promoCodeDefinition = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $promoUsageCountedAt = null;
    public function getPromoCodeDefinition(): ?PromoCode { return $this->promoCodeDefinition; }
    public function setPromoCodeDefinition(?PromoCode $code): static { $this->promoCodeDefinition = $code; return $this; }
    public function getPromoUsageCountedAt(): ?\DateTimeImmutable { return $this->promoUsageCountedAt; }
    public function markPromoUsageCounted(): void { $this->promoUsageCountedAt ??= new \DateTimeImmutable(); }
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $promoCode = null;
    #[ORM\Column(options: ['default' => 0])]
    private int $promoDiscountCents = 0;
    #[ORM\Column(nullable: true)]
    private ?int $transportCostCents = null;
    public function getPromoCode(): ?string { return $this->promoCode; }
    public function getPromoDiscountCents(): int { return $this->promoDiscountCents; }
    public function getTransportCostCents(): ?int { return $this->transportCostCents; }
    public function recordCommercialSnapshot(?string $code, int $discount, int $transport): void
    {
        $this->promoCode = $code; $this->promoDiscountCents = $discount; $this->transportCostCents = $transport;
    }
    /** @var Collection<int, OrderNotification> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderNotification::class)]
    #[ORM\OrderBy(['id' => \SortDirection::Ascending])]
    private Collection $notifications;
    public function getNotifications(): Collection { return $this->notifications; }
    public function getLogisticsTarget(): ?int
    {
        if (in_array($this->stripeRefundStatus, ['pending', 'requires_action'], true)) { return null; }
        return \App\Service\OrderManager::TRANSITIONS['logistics'][$this->state][0] ?? null;
    }
    // Legacy rows remain untracked: no retroactive proof of absence of payment.
    #[ORM\Column(options: ['default' => false])]
    private bool $paymentTrackingEnabled = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paymentInitiatedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stripeCheckoutRequest = null;

    public function isPaymentTrackingEnabled(): bool { return $this->paymentTrackingEnabled; }
    public function setPaymentTrackingEnabled(bool $enabled): static { $this->paymentTrackingEnabled = $enabled; return $this; }
    public function getPaymentInitiatedAt(): ?\DateTimeImmutable { return $this->paymentInitiatedAt; }
    public function getStripeCheckoutRequest(): ?array { return $this->stripeCheckoutRequest; }
    public function recordPaymentAttempt(array $request): void
    {
        if ($this->paymentInitiatedAt !== null) { throw new \DomainException('Une tentative de paiement existe déjà.'); }
        $this->paymentInitiatedAt = new \DateTimeImmutable();
        $this->stripeCheckoutRequest = $request;
    }

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shippingSnapshot = null;
    public function getShippingSnapshot(): ?array { return $this->shippingSnapshot; }
    public function setShippingSnapshot(?array $snapshot): static { $this->shippingSnapshot = $snapshot; return $this; }

    /** @var Collection<int, ColisCommande> */
    #[ORM\OneToMany(mappedBy: 'commande', targetEntity: ColisCommande::class, cascade: ['persist'])]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    private Collection $colis;
    public function getColis(): Collection { return $this->colis; }
    public function addColis(ColisCommande $colis): void { $this->colis->add($colis); }

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
            $total += $line->getTotalPriceCents();
        }
        return $total;
    }

    public function isStockReserved(): bool { return $this->stockReserved; }
    public function setStockReserved(bool $reserved): static { $this->stockReserved = $reserved; return $this; }
    public function getReference(): string { return 'CMD-'.$this->createdAt?->format('Ymd').'-'.$this->id; }

    public function __construct()
    {
        $this->invoices = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->colis = new ArrayCollection();
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
