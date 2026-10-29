<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Invoice, Order};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class InvoiceIssuer
{
    public function __construct(private EntityManagerInterface $em, private InvoiceNumbering $numbering, private CompanySettings $company) {}

    /** Called only after the existing payment checks, in the same order transaction. No PDF or network I/O. */
    public function issue(Order $order): Invoice
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('L’émission exige la transaction de confirmation du paiement.');
        if ($existing = $this->find($order, 'invoice')) return $existing;
        if (!$order->getPaymentConfirmedAt() || !$order->getStripeSessionId()) throw new \DomainException('Aucun paiement confirmé pour cette commande.');
        $snapshot = $this->snapshot($order);
        [$number, $date] = $this->numbering->next('invoice');
        $invoice = new Invoice($order, $number, $date, $snapshot);
        $this->em->persist($invoice);
        $order->addInvoice($invoice);
        return $invoice;
    }

    /** The current refund flow supports full refunds only. No accounting document for pending/failed refunds. */
    public function credit(Order $order, object $refund): ?Invoice
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('L’avoir exige une transaction.');
        if ($refund->status !== 'succeeded') return null;
        if ($existing = $this->find($order, 'credit')) return $existing;
        $original = $this->find($order, 'invoice');
        // Do not invent historical invoices, payment dates or identities for legacy orders.
        if (!$original) return null;
        $paymentMatches = $refund->payment_intent === $original->getPaymentReference()
            || (str_starts_with($original->getPaymentReference() ?? '', 'cs_') && $original->getPaymentReference() === $order->getStripeSessionId() && $refund->payment_intent === $order->getStripePaymentIntentId());
        if ($refund->amount !== $original->getTotalGrossCents() || !$paymentMatches) throw new \DomainException('Le remboursement ne correspond pas à la facture initiale.');
        [$number, $date] = $this->numbering->next('credit');
        $snapshot = $original->getSnapshot();
        $snapshot['refundReasonCode'] = isset($refund->reason) && is_string($refund->reason) ? $refund->reason : null;
        $snapshot['creditReason'] = match ($snapshot['refundReasonCode']) {
            'requested_by_customer'=>'Remboursement demandé par le client', 'duplicate'=>'Paiement en double', 'fraudulent'=>'Suspicion de fraude', default=>'Remboursement total confirmé',
        };
        $credit = new Invoice($order, $number, $date, $snapshot, $original, $refund->id);
        $this->em->persist($credit);
        $order->addInvoice($credit);
        return $credit;
    }

    private function find(Order $order, string $type): ?Invoice
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof Invoice && $pending->getOrder() === $order && $pending->getType() === $type) return $pending;
        }
        $existing = $this->em->getRepository(Invoice::class)->findOneBy(['order'=>$order, 'type'=>$type]);
        if ($existing) $order->addInvoice($existing);
        return $existing;
    }

    private function snapshot(Order $order): array
    {
        $buyer = $order->getInvoiceCustomerSnapshot();
        if (!$buyer) throw new \DomainException('Les coordonnées historiques de facturation ne sont pas disponibles ; une vérification est nécessaire.');
        $lines = []; $taxes = []; $netTotal = $taxTotal = $grossTotal = $discountTotal = 0;
        foreach ($order->getOrderDetails() as $line) {
            $qty = $line->getProductQuantity();
            if ($qty === null || $qty < 1 || $line->getProductPrice() === null || $line->getProductTva() === null) throw new \DomainException('Ligne de commande incomplète.');
            $unit = InvoiceAmounts::decimal($line->getProductPrice());
            $rate = InvoiceAmounts::decimal($line->getProductTva());
            $discount = $line->getCommercialDiscountCents();
            $gross = $unit * $qty - $discount;
            $net = InvoiceAmounts::net($gross, $rate); $tax = $gross - $net;
            $productDiscount = $line->getRegularUnitPriceCents() === null ? null : max(0, $line->getRegularUnitPriceCents() - $unit) * $qty;
            $lines[] = ['name'=>$line->getProductName(), 'quantity'=>$qty, 'unitNetCents'=>InvoiceAmounts::net($unit, $rate), 'unitGrossCents'=>$unit,
                'rateBasisPoints'=>$rate, 'unitTaxCents'=>$unit - InvoiceAmounts::net($unit, $rate),
                'productDiscountCents'=>$productDiscount, 'couponDiscountCents'=>$discount,
                'netCents'=>$net, 'taxCents'=>$tax, 'grossCents'=>$gross];
            $this->tax($taxes, $rate, $net, $tax);
            $netTotal += $net; $taxTotal += $tax; $grossTotal += $gross; $discountTotal += ($productDiscount ?? 0) + $discount;
        }
        if (!$lines || $order->getCarrierPrice() === null) throw new \DomainException('Commande incomplète pour la facturation.');
        $shippingGross = InvoiceAmounts::decimal($order->getCarrierPrice());
        $shippingRate = InvoiceAmounts::decimal($order->getCarrierTvaRate());
        $shippingNet = InvoiceAmounts::net($shippingGross, $shippingRate);
        $shippingTax = $shippingGross - $shippingNet;
        $this->tax($taxes, $shippingRate, $shippingNet, $shippingTax); ksort($taxes);
        $netTotal += $shippingNet; $taxTotal += $shippingTax; $grossTotal += $shippingGross;
        if ($grossTotal !== $order->getTotalCents() || $taxTotal !== InvoiceAmounts::decimal($order->getTotalTva())) throw new \DomainException('Les montants comptables ne correspondent pas à la commande.');
        return ['schema'=>1, 'operationNature'=>'goods', 'customerType'=>'consumer', 'paymentMethod'=>'Paiement en ligne via Stripe',
            'orderReference'=>$order->getReference(), 'orderPlacedAt'=>$order->getCreatedAt()?->format(DATE_ATOM),
            'seller'=>$this->company->invoiceDetails(), 'buyer'=>$buyer, 'deliveryAddress'=>$order->getDelivery(),
            'lines'=>$lines, 'taxes'=>array_values($taxes), 'promoCode'=>$order->getPromoCode(),
            'shipping'=>['name'=>$order->getCarrierName(), 'rateBasisPoints'=>$shippingRate, 'netCents'=>$shippingNet, 'taxCents'=>$shippingTax, 'grossCents'=>$shippingGross,
                'logisticsCostCents'=>$order->getTransportCostCents()],
            'totals'=>['netCents'=>$netTotal, 'taxCents'=>$taxTotal, 'grossCents'=>$grossTotal, 'discountCents'=>$discountTotal]];
    }
    private function tax(array &$taxes, int $rate, int $net, int $tax): void
    {
        $taxes[$rate] ??= ['rateBasisPoints'=>$rate, 'netCents'=>0, 'taxCents'=>0];
        $taxes[$rate]['netCents'] += $net; $taxes[$rate]['taxCents'] += $tax;
    }
}
