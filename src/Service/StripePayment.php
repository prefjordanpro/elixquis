<?php

namespace App\Service;

use App\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StripePayment
{
    public const PAYMENT_WINDOW_SECONDS = 86400;
    public function __construct(private EntityManagerInterface $em, private UrlGeneratorInterface $urls, private OrderManager $orders) {}

    private function client(): \Stripe\StripeClient
    {
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? $_SERVER['STRIPE_SECRET_KEY'] ?? '';
        if (!$key) { throw new \DomainException('Le paiement est temporairement indisponible.'); }
        return new \Stripe\StripeClient($key);
    }

    public function checkout(Order $order): string
    {
        // Commit the attempt before Stripe: a network failure must not erase this proof.
        if ($this->em->getConnection()->isTransactionActive()) {
            throw new \DomainException('Le lancement du paiement exige une transaction indépendante.');
        }
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() !== 0 || !$order->isStockReserved() || $order->getOrderDetails()->isEmpty()) {
                throw new \DomainException('Cette commande ne peut plus être payée.');
            }
            if ($order->getStripeSessionId()) { return; }
            if ($order->getCreatedAt()->getTimestamp() < time() - self::PAYMENT_WINDOW_SECONDS + 1800) {
                throw new \DomainException('Le délai de paiement est dépassé. Annulez cette commande et créez-en une nouvelle.');
            }
            if ($order->getPaymentInitiatedAt()) {
                if (!$order->getStripeCheckoutRequest()) { throw new \DomainException('Tentative Stripe incertaine : vérification manuelle nécessaire.'); }
                return;
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
            $order->recordPaymentAttempt([
                'customer_email' => $order->getUser()->getEmail(), 'client_reference_id' => (string) $order->getId(),
                'metadata' => ['order_id' => (string) $order->getId()],
                'payment_intent_data' => ['metadata' => ['order_id' => (string) $order->getId()]],
                'line_items' => $lines, 'mode' => 'payment', 'payment_method_types' => ['card'],
                'expires_at' => $order->getCreatedAt()->getTimestamp() + self::PAYMENT_WINDOW_SECONDS,
                'success_url' => str_replace('CHECKOUT_SESSION_PLACEHOLDER', '{CHECKOUT_SESSION_ID}', $success),
                'cancel_url' => $this->urls->generate('app_account_order', ['id_order' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        });
        return $this->em->wrapInTransaction(function () use ($order): string {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() !== 0 || !$order->isStockReserved()) { throw new \DomainException('Cette commande ne peut plus être payée.'); }
            $client = $this->client();
            if ($order->getStripeSessionId()) {
                $session = $client->checkout->sessions->retrieve($order->getStripeSessionId());
                if ($session->status === 'open') { return $session->url; }
                throw new \DomainException('Cette session de paiement est terminée.');
            }
            // Do not replay an old uncertain request after Stripe's idempotency retention window.
            if ($order->getCreatedAt()->getTimestamp() < time() - self::PAYMENT_WINDOW_SECONDS + 1800) {
                throw new \DomainException('Tentative Stripe incertaine ou délai dépassé : vérification nécessaire.');
            }
            $session = $client->checkout->sessions->create($order->getStripeCheckoutRequest(),
                ['idempotency_key' => 'checkout-order-'.$order->getId()]);
            $order->setStripeSessionId($session->id);
            return $session->url;
        });
    }

    public function verify(Order $order): void
    {
        if (!$order->getStripeSessionId()) { throw new \DomainException('Aucun paiement associé à cette commande.'); }
        $client = $this->client();
        $session = $client->checkout->sessions->retrieve($order->getStripeSessionId());
        $historicalIntent = null;
        if (HistoricalStripePaymentMatch::isCandidate($order, $session)) {
            $remoteIntent = $session->payment_intent ?? null;
            $intentId = is_string($remoteIntent) ? $remoteIntent : ($remoteIntent->id ?? null);
            if (!$intentId || $session->id !== $order->getStripeSessionId()) {
                throw new \DomainException('Le paiement historique ne correspond pas à la commande.');
            }
            // Ne pas se fier au seul statut paid : relire l'encaissement et les identifiants Stripe.
            $historicalIntent = $client->paymentIntents->retrieve($intentId);
            if (!HistoricalStripePaymentMatch::matches($order, $session, $historicalIntent)) {
                throw new \DomainException('Le paiement historique ne correspond pas à la commande.');
            }
        }
        $this->orders->confirmPayment($order, $session, $historicalIntent);
    }

    /** Webhook : seule une session réellement expirée autorise l'annulation, même avant 24 h. */
    public function expireUnpaid(Order $order, bool $expiredWebhook = false, bool $dryRun = false): string
    {
        // Même verrou que checkout et confirmPayment : sérialiser paiement et restitution du stock.
        $process = function () use ($order, $expiredWebhook, $dryRun): string {
            $this->em->refresh($order, $dryRun ? LockMode::NONE : LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() !== 0) { return 'unchanged'; }
            if (!$expiredWebhook && $order->getCreatedAt()->getTimestamp() >= time() - self::PAYMENT_WINDOW_SECONDS) {
                return 'recent';
            }
            $id = $order->getStripeSessionId();
            // L'absence de session n'est pas une preuve d'absence de paiement.
            if (!$id || trim($id) === '') {
                if ($order->getPaymentInitiatedAt()) { return 'uncertain'; }
                if (!$order->isPaymentTrackingEnabled() || $order->getStripePaymentIntentId() || $expiredWebhook) { return 'unverifiable'; }
                if ($dryRun) { return 'would_cancel_uninitiated'; }
                $this->orders->cancelUnpaid($order);
                return 'cancelled_uninitiated';
            }
            $client = $this->client();
            $session = $client->checkout->sessions->retrieve($id);
            $this->assertSessionOrder($order, $session);
            if ($session->payment_status === 'paid') {
                if ($dryRun) {
                    $intent = $session->payment_intent ?? null;
                    $intentId = is_string($intent) ? $intent : ($intent->id ?? null);
                    if ($session->currency !== 'eur' || $session->amount_total !== $order->getTotalCents()
                        || !$order->isStockReserved()
                        || ($intentId && $order->getStripePaymentIntentId() && $intentId !== $order->getStripePaymentIntentId())) {
                        throw new \DomainException('Le paiement ne correspond pas à la commande.');
                    }
                    return 'would_sync';
                }
                $this->orders->confirmPayment($order, $session);
                return 'paid';
            }
            if ($session->payment_status !== 'unpaid' || !in_array($session->status, ['open', 'expired'], true)) {
                return 'pending'; // complete/unpaid peut encore être en cours de traitement.
            }
            if (!$this->intentAllowsExpiration($order, $session, $client)) { return 'pending'; }
            if ($session->status === 'open') {
                if ($expiredWebhook) { return 'pending'; }
                if ($dryRun) { return 'would_expire'; }
                // Une erreur/concurrence Stripe conserve la commande et son stock pour une prochaine tentative.
                $session = $client->checkout->sessions->expire($id);
                $this->assertSessionOrder($order, $session);
            }
            if ($session->payment_status === 'paid') {
                $this->orders->confirmPayment($order, $session);
                return 'paid';
            }
            if ($session->status !== 'expired' || $session->payment_status !== 'unpaid'
                || !$this->intentAllowsExpiration($order, $session, $client)) { return 'pending'; }
            if ($dryRun) { return 'would_cancel_expired'; }
            $this->orders->cancelUnpaid($order);
            return 'cancelled';
        };
        // wrapInTransaction() déclenche un flush : ne jamais l'utiliser en simulation.
        return $dryRun ? $process() : $this->em->wrapInTransaction($process);
    }

    private function assertSessionOrder(Order $order, object $session): void
    {
        if ($session->id !== $order->getStripeSessionId()
            || (string) $session->client_reference_id !== (string) $order->getId()) {
            throw new \DomainException('La session Stripe ne correspond pas à la commande.');
        }
    }

    private function intentAllowsExpiration(Order $order, object $session, \Stripe\StripeClient $client): bool
    {
        $sessionIntent = $session->payment_intent ?? null;
        $sessionId = is_string($sessionIntent) ? $sessionIntent : ($sessionIntent->id ?? null);
        $storedId = $order->getStripePaymentIntentId();
        if ($storedId && $sessionId && $storedId !== $sessionId) {
            throw new \DomainException('Le PaymentIntent ne correspond pas à la commande.');
        }
        $id = $sessionId ?? $storedId;
        if (!$id) { return true; }
        $intent = $client->paymentIntents->retrieve($id);
        return $intent->id === $id && in_array($intent->status, ['requires_payment_method', 'canceled'], true);
    }

    public function cancel(Order $order): void
    {
        // Même verrou que checkout : aucune session payable ne peut apparaître pendant l’annulation.
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() === 4) { return; }
            if ($order->getState() !== 0) { throw new \DomainException('Une commande payée nécessite un remboursement avant son annulation.'); }
            if (!$order->getStripeSessionId() && $order->getPaymentInitiatedAt()) {
                throw new \DomainException('Tentative Stripe incertaine : aucune annulation automatique.');
            }
            if ($id = $order->getStripeSessionId()) {
                $client = $this->client();
                $session = $client->checkout->sessions->retrieve($id);
                if ($session->status === 'open') { $session = $client->checkout->sessions->expire($id); }
                if ($session->status !== 'expired') { throw new \DomainException('Le paiement est terminé ou en cours de confirmation.'); }
            }
            $this->orders->cancelUnpaid($order);
        });
    }

    /** Renvoie true si le remboursement devra être effectué manuellement dans Stripe. */
    public function cancelForAdmin(Order $order): bool
    {
        return $this->em->wrapInTransaction(function () use ($order): bool {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() === 4) { return false; }
            if ($order->getState() === 0) {
                // Empêcher un paiement après la restitution du stock d’une commande impayée.
                $this->cancel($order);
                return false;
            }
            if (!in_array($order->getState(), [1, 2], true)) {
                throw new \DomainException('Impossible d’annuler une commande déjà expédiée ou livrée.');
            }
            $this->orders->cancelForAdmin($order);
            return true;
        });
    }
}
