<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/** Accounting documents are immutable. Corrections create a separate document. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_INVOICE_ORDER_TYPE', columns: ['order_id', 'type'])]
class Invoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Order $order;
    #[ORM\Column(length: 40, unique: true)]
    private string $number;
    #[ORM\Column(length: 16)]
    private string $type;
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $originalInvoice;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $issuedAt;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $paidAt;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $saleAt;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentReference;
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $refundReference;
    #[ORM\Column(type: 'json')]
    private array $snapshot;
    #[ORM\Column(type: 'text')]
    private string $searchText;
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceLine::class, cascade: ['persist'])]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    private Collection $lines;

    public function __construct(Order $order, string $number, \DateTimeImmutable $issuedAt, array $snapshot, ?self $original = null, ?string $refundReference = null)
    {
        if (!$order->getPaymentConfirmedAt() && !$original) throw new \DomainException('Aucune confirmation de paiement enregistrée.');
        $this->order = $order; $this->number = $number; $this->issuedAt = $this->createdAt = $issuedAt;
        $this->type = $original ? 'credit' : 'invoice'; $this->originalInvoice = $original;
        $this->paidAt = $original?->getPaidAt() ?? $order->getPaymentConfirmedAt();
        $this->saleAt = $original?->getSaleAt(); // Delivery has no reliable dated event in the existing model.
        $this->lines = new ArrayCollection();
        foreach ($snapshot['lines'] as $position => $line) $this->lines->add(new InvoiceLine($this, $position + 1, $line));
        unset($snapshot['lines']);
        $this->snapshot = array_merge($snapshot, ['issuedAt'=>$issuedAt->format(DATE_ATOM), 'paidAt'=>$this->paidAt->format(DATE_ATOM), 'saleAt'=>$this->saleAt?->format(DATE_ATOM)]);
        $this->paymentReference = $original?->getPaymentReference() ?? $order->getStripePaymentIntentId() ?? $order->getStripeSessionId();
        $this->refundReference = $refundReference;
        $this->searchText = mb_strtolower(implode(' ', [$number, $snapshot['orderReference'], $snapshot['buyer']['firstname'], $snapshot['buyer']['lastname'], $snapshot['buyer']['email']]));
    }
    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getNumber(): string { return $this->number; }
    public function getType(): string { return $this->type; }
    public function getLabel(): string { return $this->type === 'credit' ? 'AVOIR' : 'FACTURE'; }
    public function getStatus(): string { return $this->type === 'invoice' ? 'Payée' : 'Émis'; }
    public function getOriginalInvoice(): ?self { return $this->originalInvoice; }
    public function getIssuedAt(): \DateTimeImmutable { return new \DateTimeImmutable($this->snapshot['issuedAt']); }
    public function getPaidAt(): \DateTimeImmutable { return new \DateTimeImmutable($this->snapshot['paidAt']); }
    public function getSaleAt(): ?\DateTimeImmutable { return $this->snapshot['saleAt'] ? new \DateTimeImmutable($this->snapshot['saleAt']) : null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->getIssuedAt(); }
    public function getCurrency(): string { return $this->currency; }
    public function getPaymentReference(): ?string { return $this->paymentReference; }
    public function getRefundReference(): ?string { return $this->refundReference; }
    public function getLines(): Collection { return new ArrayCollection($this->lines->toArray()); }
    public function getSnapshot(): array { return $this->snapshot + ['lines'=>array_map(static fn (InvoiceLine $line) => $line->getData(), $this->lines->toArray())]; }
    public function getTotalNetCents(): int { return $this->snapshot['totals']['netCents']; }
    public function getTotalTaxCents(): int { return $this->snapshot['totals']['taxCents']; }
    public function getTotalGrossCents(): int { return $this->snapshot['totals']['grossCents']; }
}
