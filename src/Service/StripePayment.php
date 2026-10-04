<?php

namespace App\Service;

use App\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StripePayment
{
    public function __construct(private EntityManagerInterface $em, private UrlGeneratorInterface $urls, private OrderManager $orders) {}

    private function client(): \Stripe\StripeClient
    {
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? $_SERVER['STRIPE_SECRET_KEY'] ?? '';
        if (!$key) { throw new \DomainException('Le paiement est temporairement indisponible.'); }
        return new \Stripe\StripeClient($key);
    }

    public function checkout(Order $order): string
    {
        return $this->em->wrapInTransaction(function () use ($order): string {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() !== 0 || !$order->isStockReserved() || $order->getOrderDetails()->isEmpty()) {
                throw new \DomainException('Cette commande ne peut plus être payée.');
            }
            $client = $this->client();
            if ($order->getStripeSessionId()) {
                $session = $client->checkout->sessions->retrieve($order->getStripeSessionId());
                if ($session->status === 'open') { return $session->url; }
                throw new \DomainException('Cette session de paiement est terminée. Consultez votre commande.');
            }
            if ($order->getCreatedAt()->getTimestamp() < time() - 1700) {
                throw new \DomainException('Le délai de paiement est dépassé. Annulez cette commande et créez-en une nouvelle.');
            }
            $lines = [];
            foreach ($order->getOrderDetails() as $detail) {
                $lines[] = ['price_data' => ['currency' => 'eur', 'unit_amount' => (int) round($detail->getProductPrice() * 100),
                    'product_data' => ['name' => $detail->getProductName()]], 'quantity' => $detail->getProductQuantity()];
            }
            if ($order->getCarrierPrice() > 0) {
                $lines[] = ['price_data' => ['currency' => 'eur', 'unit_amount' => (int) round($order->getCarrierPrice() * 100),
                    'product_data' => ['name' => 'Livraison : '.$order->getCarrierName()]], 'quantity' => 1];
            }
            $success = $this->urls->generate('app_payment_success', ['stripe_session_id' => 'CHECKOUT_SESSION_PLACEHOLDER'], UrlGeneratorInterface::ABSOLUTE_URL);
            $session = $client->checkout->sessions->create([
                'customer_email' => $order->getUser()->getEmail(), 'client_reference_id' => (string) $order->getId(),
                'line_items' => $lines, 'mode' => 'payment', 'payment_method_types' => ['card'],
                'expires_at' => $order->getCreatedAt()->getTimestamp() + 3600,
                'success_url' => str_replace('CHECKOUT_SESSION_PLACEHOLDER', '{CHECKOUT_SESSION_ID}', $success),
                'cancel_url' => $this->urls->generate('app_account_order', ['id_order' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ], ['idempotency_key' => 'checkout-order-'.$order->getId()]);
            $order->setStripeSessionId($session->id);
            return $session->url;
        });
    }

    public function verify(Order $order): void
    {
        if (!$order->getStripeSessionId()) { throw new \DomainException('Aucun paiement associé à cette commande.'); }
        $this->orders->confirmPayment($order, $this->client()->checkout->sessions->retrieve($order->getStripeSessionId()));
    }

    public function cancel(Order $order): void
    {
        // Même verrou que checkout : aucune session payable ne peut apparaître pendant l’annulation.
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() === 4) { return; }
            if ($order->getState() !== 0) { throw new \DomainException('Une commande payée nécessite un remboursement avant son annulation.'); }
            if ($id = $order->getStripeSessionId()) {
                $client = $this->client();
                $session = $client->checkout->sessions->retrieve($id);
                if ($session->status === 'open') { $session = $client->checkout->sessions->expire($id); }
                if ($session->status !== 'expired') { throw new \DomainException('Le paiement est terminé ou en cours de confirmation.'); }
            }
            $this->orders->cancelUnpaid($order);
        });
    }
}
