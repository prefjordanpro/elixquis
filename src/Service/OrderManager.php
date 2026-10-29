<?php

namespace App\Service;

use App\Entity\{Address, Carrier, Order, OrderDetail, Product, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class OrderManager
{
    // Operation-specific edges: payment/refund callers still verify Stripe and cancellation keeps its stock guards.
    public const TRANSITIONS = [
        'payment' => [0 => [1]],
        'logistics' => [1 => [2], 2 => [3], 3 => [5]],
        'unpaid_cancellation' => [0 => [4]],
        'admin_cancellation' => [0 => [4], 1 => [4], 2 => [4]],
        'refund' => [1 => [6], 2 => [6], 3 => [6], 4 => [6], 5 => [6], 7 => [6]],
        'cancellation_request' => [1 => [7], 2 => [7]],
        'cancellation_resolution' => [7 => [1, 2]],
    ];

    public static function allows(string $operation, ?int $from, int $to): bool
    {
        return in_array($to, self::TRANSITIONS[$operation][$from] ?? [], true);
    }

    private CommercialPricing $pricing;
    public function __construct(private EntityManagerInterface $em, private OrderEmails $emails, private InvoiceIssuer $invoices, ?CommercialPricing $pricing = null) { $this->pricing = $pricing ?? new CommercialPricing($em); }

    public function create(User $user, Address $address, Carrier $carrier, array $lines, ?\App\Shipping\DeliverySelection $delivery = null, ?string $promoCode = null): Order
    {
        if (!$lines || $address->getUser()?->getId() !== $user->getId()) {
            throw new \DomainException('Le panier ou l’adresse de livraison est invalide.');
        }
        return $this->em->wrapInTransaction(function () use ($user, $address, $carrier, $lines, $delivery, $promoCode): Order {
            if ($delivery) { $carrier = $delivery->carrier(); }
            $order = (new Order())->setUser($user)->setCreatedAt(new \DateTime())
                ->setState(0)->setCarrierName($carrier->getName())->setCarrierPrice($carrier->getPrice())
                ->setCarrierTvaRate($carrier->getTva())->setStockReserved(true)->setPaymentTrackingEnabled(true)
                ->setDelivery(sprintf("%s %s\n%s\n%s %s — %s\n%s", $address->getFirstname(), $address->getLastname(),
                    $address->getAddress(), $address->getPostal(), $address->getCity(), $address->getCountry(), $address->getPhone()));
            $order->recordInvoiceCustomer(['firstname'=>$address->getFirstname(), 'lastname'=>$address->getLastname(), 'email'=>$user->getEmail(),
                'address'=>$address->getAddress(), 'postal'=>$address->getPostal(), 'city'=>$address->getCity(), 'country'=>$address->getCountry(), 'addressSource'=>'delivery']);
            if ($delivery) { $order->setShippingSnapshot($delivery->snapshot); }
            foreach ($delivery?->snapshot['colisage'] ?? [] as $index => $colis) {
                $emballage = isset($colis['emballage']['id']) ? $this->em->find(\App\Entity\Emballage::class, $colis['emballage']['id']) : null;
                if ($colis['emballage']['id'] !== null) {
                    if (!$emballage) { throw new \DomainException('Un emballage a changé. Vérifiez à nouveau votre livraison.'); }
                    $this->em->refresh($emballage, LockMode::PESSIMISTIC_READ);
                    if (!$emballage->isActif() || !$emballage->estComplet() || $emballage->snapshot() !== $colis['emballage']) {
                        throw new \DomainException('Un emballage a changé. Vérifiez à nouveau votre livraison.');
                    }
                }
                $order->addColis(new \App\Entity\ColisCommande($order, $index + 1, $colis, $emballage));
            }
            ksort($lines);
            // Relecture sous verrou avant tout calcul commercial : aucun prix navigateur.
            foreach ($lines as &$line) {
                $current = $this->em->find(Product::class, $line['object']->getId());
                if (!$current) { throw new \DomainException('Un produit n’est plus disponible.'); }
                $this->em->refresh($current, LockMode::PESSIMISTIC_WRITE);
                $line['object'] = $current;
            }
            unset($line);
            $quote = $this->pricing->quote($lines, $promoCode, true);
            $transportCents = (int) round($carrier->getPrice() * 100);
            $order->recordCommercialSnapshot($quote['coupon']?->getCode(), $quote['discountCents'], $transportCents);
            if ($quote['freeShipping']) { $order->setCarrierPrice(0); }
            $order->setPromoCodeDefinition($quote['coupon']);
            foreach ($lines as $key => $line) {
                $product = $this->em->find(Product::class, $line['object']->getId());
                if (!$product) { throw new \DomainException('Un produit n’est plus disponible.'); }
                $this->em->refresh($product, LockMode::PESSIMISTIC_WRITE);
                $qty = $line['qty'];
                if ($delivery) {
                    $facts = array_values(array_filter($delivery->snapshot['items'], static fn ($item) => $item['id'] === $product->getId()))[0] ?? null;
                    if (!$facts || $facts['qty'] !== $qty || $facts['weight_grams'] !== $product->getShippingWeightGrams()
                        || $facts['price_cents'] !== $quote['unitCents'][$key]) {
                        throw new \DomainException('Votre sélection a changé. Vérifiez à nouveau le panier et la livraison.');
                    }
                }
                if (!is_int($qty) || $qty < 1 || !$product->isActive() || $qty > $product->getStock()) {
                    throw new \DomainException('Stock insuffisant pour '.$product->getName().'.');
                }
                $product->setStock($product->getStock() - $qty);
                $order->addOrderDetail((new OrderDetail())->setProduct($product)->setProductName($product->getName())
                    ->setProductIllustration($product->getIllustration())->setProductQuantity($qty)
                    ->setProductPrice($quote['unitCents'][$key] / 100)->setProductTva($product->getTva())
                    ->setRegularUnitPriceCents((int) round($product->getRegularPriceWt() * 100))
                    ->setCommercialDiscountCents($quote['discounts'][$key]));
            }
            $this->em->persist($order);
            return $order;
        });
    }

    /** Seules les données Stripe vérifiées par le serveur sont acceptées. */
    public function confirmPayment(Order $order, object $session, ?object $historicalIntent = null): void
    {
        if ($session->payment_status !== 'paid') {
            throw new \DomainException('Le paiement n’est pas confirmé.');
        }
        $this->em->wrapInTransaction(function () use ($order, $session, $historicalIntent): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $historicalMatch = $historicalIntent !== null && HistoricalStripePaymentMatch::matches($order, $session, $historicalIntent);
            if ($historicalMatch && $this->em->getRepository(Order::class)->count(['stripe_session_id' => $session->id]) !== 1) {
                throw new \DomainException('La session Stripe est associée à plusieurs commandes.');
            }
            if ($session->id !== $order->getStripeSessionId() || $session->payment_status !== 'paid'
                || $session->currency !== 'eur' || $session->amount_total !== $order->getTotalCents()
                || (!$historicalMatch && (string) $session->client_reference_id !== (string) $order->getId())) {
                throw new \DomainException('Le paiement ne correspond pas à la commande.');
            }
            if (isset($session->payment_intent)) {
                $intent = is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent->id;
                if ($order->getStripePaymentIntentId() && $order->getStripePaymentIntentId() !== $intent) {
                    throw new \DomainException('Le paiement Stripe enregistré ne correspond pas à la session.');
                }
                $order->setStripePaymentIntentId($intent);
            }
            if (in_array($order->getState(), [1, 2, 3, 4, 5, 6, 7], true)) {
                if ($order->getPaymentConfirmedAt() && $order->getInvoiceCustomerSnapshot()) $this->invoices->issue($order);
                return;
            }
            if (!self::allows('payment', $order->getState(), 1) || (!$historicalMatch && !$order->isStockReserved())) {
                throw new \DomainException('Cette commande ne peut plus être payée.');
            }
            if ($order->getPromoCode() !== null && $order->getPromoDiscountCents() > 0 && $order->getPromoUsageCountedAt() === null) {
                if ($code = $order->getPromoCodeDefinition()) {
                    // Même transaction que le paiement ; le verrou évite les incréments perdus entre commandes.
                    $this->em->refresh($code, LockMode::PESSIMISTIC_WRITE);
                    $code->recordConfirmedUse();
                }
                $order->markPromoUsageCounted();
            }
            $order->setState(1);
            $order->recordPaymentConfirmation();
            if ($order->getInvoiceCustomerSnapshot()) $this->invoices->issue($order);
            $this->emails->queue($order);
        });
    }

    /** La session Stripe doit avoir été expirée avant la libération du stock. */
    public function cancelUnpaid(Order $order): void
    {
        $this->cancel($order, 'unpaid_cancellation');
    }

    /** Réservé au parcours administrateur ; aucun remboursement n’est effectué ici. */
    public function cancelForAdmin(Order $order): void
    {
        $this->cancel($order, 'admin_cancellation');
    }

    private function cancel(Order $order, string $operation): void
    {
        $this->em->wrapInTransaction(function () use ($order, $operation): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() === 4) { return; }
            $shipment = $this->em->getRepository(\App\Entity\Shipment::class)->findOneBy(['order' => $order]);
            if ($shipment?->isActive()) { throw new \DomainException('Une expédition Sendcloud existe ou reste à vérifier. Annulez-la dans Sendcloud puis actualisez son suivi avant d’annuler la commande.'); }
            if (!self::allows($operation, $order->getState(), 4)) {
                throw new \DomainException('L’annulation n’est pas autorisée pour le statut actuel de cette commande.');
            }
            $this->releaseStock($order);
            $order->setState(4);
            $this->emails->queue($order);
        });
    }

    /** Appelé uniquement avec un objet de remboursement confirmé par Stripe. */
    public function synchronizeRefund(Order $order, object $refund): void
    {
        $this->em->wrapInTransaction(function () use ($order, $refund): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if (!in_array($order->getState(), [1, 2, 3, 4, 5, 6, 7], true)
                || !$order->getStripePaymentIntentId()
                || $refund->payment_intent !== $order->getStripePaymentIntentId()
                || $refund->currency !== 'eur' || $refund->amount !== $order->getTotalCents()
                || !is_string($refund->id) || !str_starts_with($refund->id, 're_')
                || !in_array($refund->status, ['succeeded', 'pending', 'requires_action', 'failed', 'canceled'], true)
                || ($order->getStripeRefundId() && $order->getStripeRefundId() !== $refund->id)) {
                throw new \DomainException('Le remboursement Stripe ne correspond pas au remboursement total de cette commande.');
            }
            $order->setStripeRefundId($refund->id)->setStripeRefundStatus($refund->status);
            if ($refund->status === 'succeeded') $this->invoices->credit($order, $refund);
            if ($refund->status === 'succeeded' && $order->getState() !== 6) {
                if (!self::allows('refund', $order->getState(), 6)) { throw new \DomainException('Ce remboursement n’est pas autorisé.'); }
                if ($order->getState() === 7) {
                    // Un remboursement externe confirmé résout aussi la demande, sans la perdre.
                    $pending = $this->em->getRepository(\App\Entity\CancellationRequest::class)->findOneBy(['order' => $order, 'decision' => 'pending']);
                    $pending?->resolve('accepted');
                    $order->setState(4);
                }
                $order->setStateBeforeRefund($order->getState());
                // Les articles expédiés/livrés ne reviennent pas physiquement par un remboursement.
                if (in_array($order->getState(), [1, 2, 4], true)) { $this->releaseStock($order); }
                $order->setState(6);
                $this->emails->queue($order);
            } elseif ($refund->status !== 'succeeded' && $order->getState() === 6) {
                // Stripe peut signaler un échec bancaire après une première confirmation.
                $order->setState($order->getStateBeforeRefund() ?? 4);
            }
        });
    }

    private function releaseStock(Order $order): void
    {
        if (!$order->isStockReserved()) { return; }
        $shipment = $this->em->getRepository(\App\Entity\Shipment::class)->findOneBy(['order' => $order]);
        if ($shipment?->isActive()) { return; } // Un remboursement ne remet pas physiquement un colis en stock.
        $details = $order->getOrderDetails()->toArray();
        usort($details, static fn ($a, $b) => ($a->getProduct()?->getId() ?? 0) <=> ($b->getProduct()?->getId() ?? 0));
        foreach ($details as $detail) {
            if ($product = $detail->getProduct()) {
                $this->em->refresh($product, LockMode::PESSIMISTIC_WRITE);
                $product->setStock($product->getStock() + $detail->getProductQuantity());
            }
        }
        $order->setStockReserved(false);
    }

    public function advance(Order $order, int $from, int $to): void
    {
        $this->em->wrapInTransaction(function () use ($order, $from, $to): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if (in_array($order->getStripeRefundStatus(), ['pending', 'requires_action'], true)
                || !self::allows('logistics', $from, $to)
                || !in_array($order->getState(), [$from, $to], true)) {
                throw new \DomainException('Ce changement de statut n’est pas autorisé.');
            }
            if ($order->getState() === $to) { return; }
            $order->setState($to);
            $this->emails->queue($order);
        });
    }
}
