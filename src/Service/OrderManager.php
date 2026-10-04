<?php

namespace App\Service;

use App\Entity\{Address, Carrier, Order, OrderDetail, Product, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class OrderManager
{
    public function __construct(private EntityManagerInterface $em) {}

    public function create(User $user, Address $address, Carrier $carrier, array $lines): Order
    {
        if (!$lines || $address->getUser()?->getId() !== $user->getId()) {
            throw new \DomainException('Le panier ou l’adresse de livraison est invalide.');
        }
        return $this->em->wrapInTransaction(function () use ($user, $address, $carrier, $lines): Order {
            $order = (new Order())->setUser($user)->setCreatedAt(new \DateTime())
                ->setState(0)->setCarrierName($carrier->getName())->setCarrierPrice($carrier->getPrice())
                ->setCarrierTvaRate($carrier->getTva())->setStockReserved(true)
                ->setDelivery(sprintf("%s %s\n%s\n%s %s — %s\n%s", $address->getFirstname(), $address->getLastname(),
                    $address->getAddress(), $address->getPostal(), $address->getCity(), $address->getCountry(), $address->getPhone()));
            ksort($lines);
            foreach ($lines as $line) {
                $product = $this->em->find(Product::class, $line['object']->getId());
                if (!$product) { throw new \DomainException('Un produit n’est plus disponible.'); }
                $this->em->refresh($product, LockMode::PESSIMISTIC_WRITE);
                $qty = $line['qty'];
                if (!is_int($qty) || $qty < 1 || !$product->isActive() || $qty > $product->getStock()) {
                    throw new \DomainException('Stock insuffisant pour '.$product->getName().'.');
                }
                $product->setStock($product->getStock() - $qty);
                $order->addOrderDetail((new OrderDetail())->setProduct($product)->setProductName($product->getName())
                    ->setProductIllustration($product->getIllustration())->setProductQuantity($qty)
                    ->setProductPrice($product->getPriceWt())->setProductTva($product->getTva()));
            }
            $this->em->persist($order);
            return $order;
        });
    }

    /** Seules les données Stripe vérifiées par le serveur sont acceptées. */
    public function confirmPayment(Order $order, object $session): void
    {
        if ($session->payment_status !== 'paid') {
            throw new \DomainException('Le paiement n’est pas confirmé.');
        }
        $this->em->wrapInTransaction(function () use ($order, $session): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($session->id !== $order->getStripeSessionId() || $session->payment_status !== 'paid'
                || $session->currency !== 'eur' || $session->amount_total !== $order->getTotalCents()
                || (string) $session->client_reference_id !== (string) $order->getId()) {
                throw new \DomainException('Le paiement ne correspond pas à la commande.');
            }
            if (in_array($order->getState(), [1, 2, 3, 5], true)) { return; }
            if ($order->getState() !== 0 || !$order->isStockReserved()) {
                throw new \DomainException('Cette commande ne peut plus être payée.');
            }
            $order->setState(1);
        });
    }

    /** La session Stripe doit avoir été expirée avant la libération du stock. */
    public function cancelUnpaid(Order $order): void
    {
        $this->cancel($order, [0]);
    }

    /** Réservé au parcours administrateur ; aucun remboursement n’est effectué ici. */
    public function cancelForAdmin(Order $order): void
    {
        $this->cancel($order, [0, 1, 2]);
    }

    private function cancel(Order $order, array $allowedStates): void
    {
        $this->em->wrapInTransaction(function () use ($order, $allowedStates): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() === 4) { return; }
            if (!in_array($order->getState(), $allowedStates, true)) {
                throw new \DomainException('L’annulation n’est pas autorisée pour le statut actuel de cette commande.');
            }
            if ($order->isStockReserved()) {
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
            $order->setState(4);
        });
    }

    public function advance(Order $order, int $from, int $to): void
    {
        $this->em->wrapInTransaction(function () use ($order, $from, $to): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if (!in_array([$from, $to], [[1, 2], [2, 3], [3, 5]], true) || $order->getState() !== $from) {
                throw new \DomainException('Ce changement de statut n’est pas autorisé.');
            }
            $order->setState($to);
        });
    }
}
