<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/** One database row serializes allocation and date ordering across both document series. */
#[ORM\Entity]
class InvoiceSequence
{
    #[ORM\Id, ORM\Column]
    private int $id = 1;
    #[ORM\Column]
    private int $year = 0;
    #[ORM\Column]
    private int $invoiceValue = 0;
    #[ORM\Column]
    private int $creditValue = 0;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastIssuedAt = null;
}
