<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_INVOICE_LINE_POSITION', columns: ['invoice_id', 'position'])]
class InvoiceLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Invoice $invoice;
    #[ORM\Column]
    private int $position;
    #[ORM\Column(type: 'json')]
    private array $data;
    public function __construct(Invoice $invoice, int $position, array $data)
    {
        $this->invoice = $invoice; $this->position = $position; $this->data = $data;
    }
    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getPosition(): int { return $this->position; }
    public function getData(): array { return $this->data; }
}
