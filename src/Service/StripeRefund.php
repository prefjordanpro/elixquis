<?php

namespace App\Service;

use App\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Remboursement total : aucun montant ou identifiant de paiement fourni par le navigateur. */
final class StripeRefund
{
    public function __construct(private EntityManagerInterface $em, private OrderManager $orders, private LoggerInterface $logger) {}

    private function client(): \Stripe\StripeClient
    {
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? $_SERVER['STRIPE_SECRET_KEY'] ?? '';
        if (!$key) { throw new \DomainException('Le remboursement Stripe est temporairement indisponible.'); }
        return new \Stripe\StripeClient($key);
    }

    public function refund(Order $order): string
    {
        return $this->em->wrapInTransaction(function () use ($order): string {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() === 6) { return 'succeeded'; }
            if (!in_array($order->getState(), [1, 2, 3, 4, 5], true)) {
                throw new \DomainException('Cette commande ne possède pas de paiement remboursable.');
            }
            $client = $this->client();
            if ($order->getStripeRefundId()) {
                // Une opération déjà créée est synchronisée, jamais recréée.
                $refund = $client->refunds->retrieve($order->getStripeRefundId());
            } else {
                $intentId = $this->resolvePayment($order, $client);
                $intent = $client->paymentIntents->retrieve($intentId);
                if ($intent->id !== $intentId || $intent->status !== 'succeeded'
                    || $intent->currency !== 'eur' || $intent->amount_received !== $order->getTotalCents()) {
                    throw new \DomainException('Le paiement Stripe ne correspond pas au montant total encaissé de cette commande.');
                }
                // Retrouver aussi une opération effectuée dans Stripe ou après une réponse réseau perdue.
                $existing = $client->refunds->all(['payment_intent' => $intentId, 'limit' => 100]);
                $refund = null;
                foreach ($existing->data as $candidate) {
                    if (in_array($candidate->status, ['failed', 'canceled'], true)) { continue; }
                    if ($candidate->amount !== $order->getTotalCents() || $refund !== null || $existing->has_more) {
                        throw new \DomainException('Des remboursements existent déjà dans Stripe. Vérifiez-les manuellement : seuls les remboursements totaux sont pris en charge.');
                    }
                    $refund = $candidate;
                }
                if ($existing->has_more) {
                    throw new \DomainException('L’historique Stripe doit être vérifié manuellement avant remboursement.');
                }
                if (!$refund) {
                    $refund = $client->refunds->create([
                        'payment_intent' => $intentId,
                        'amount' => $order->getTotalCents(),
                        'metadata' => ['order_id' => (string) $order->getId()],
                    ], ['idempotency_key' => 'full-refund-order-'.$order->getId().'-'.$intentId]);
                }
            }
            $this->em->flush();
            $this->orders->synchronizeRefund($order, $refund);
            $this->log($order);
            return $refund->status;
        });
    }

    /** Appelé après vérification de la signature Stripe ; relit le statut actuel pour éviter les événements désordonnés. */
    public function synchronize(Order $order, string $refundId): void
    {
        $this->em->wrapInTransaction(function () use ($order, $refundId): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $client = $this->client();
            $this->resolvePayment($order, $client);
            $refund = $client->refunds->retrieve($refundId);
            // Un remboursement partiel externe ne suffit pas à marquer la commande remboursée.
            if ($refund->amount !== $order->getTotalCents()) {
                $this->logger->warning('Remboursement Stripe partiel non appliqué à la commande.', ['order' => $order->getId(), 'refund' => $refundId]);
                return;
            }
            $this->em->flush();
            $this->orders->synchronizeRefund($order, $refund);
            $this->log($order);
        });
    }

    private function resolvePayment(Order $order, \Stripe\StripeClient $client): string
    {
        if ($order->getStripePaymentIntentId()) { return $order->getStripePaymentIntentId(); }
        if (!$order->getStripeSessionId()) { throw new \DomainException('Aucun paiement Stripe enregistré pour cette commande.'); }
        $session = $client->checkout->sessions->retrieve($order->getStripeSessionId());
        if ($session->id !== $order->getStripeSessionId() || $session->payment_status !== 'paid'
            || $session->currency !== 'eur' || $session->amount_total !== $order->getTotalCents()
            || (string) $session->client_reference_id !== (string) $order->getId()
            || !is_string($session->payment_intent) || !str_starts_with($session->payment_intent, 'pi_')) {
            throw new \DomainException('Aucun paiement Stripe valide correspondant à cette commande.');
        }
        $order->setStripePaymentIntentId($session->payment_intent);
        return $session->payment_intent;
    }

    private function log(Order $order): void
    {
        $this->logger->info('Remboursement Stripe synchronisé.', ['order' => $order->getId(),
            'refund' => $order->getStripeRefundId(), 'status' => $order->getStripeRefundStatus()]);
    }
}
