<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{CancellationRequest, Order, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Intervention explicite sur les commandes anciennes ; aucun changement au parcours normal. */
final class HistoricalOrderCancellation
{
    public const REASON = 'Annulation manuelle d’une commande historique non vérifiable';

    public function __construct(private EntityManagerInterface $em, private OrderManager $orders) {}

    public function isEligible(Order $order): bool
    {
        if ($order->getState() !== 0 || !$order->getCreatedAt()
            || $order->getCreatedAt()->getTimestamp() >= time() - StripePayment::PAYMENT_WINDOW_SECONDS
            || $order->getStripeRefundId() || $order->getShipment()?->isActive()) { return false; }
        if ($order->getPaymentInitiatedAt() && !$order->getStripeSessionId()) { return false; }
        $sessionId = $order->getStripeSessionId();
        $storedIntent = $order->getStripePaymentIntentId();
        if (!$sessionId || trim($sessionId) === '') {
            return !$storedIntent; // Décision manuelle autorisée, jamais utilisée par l'expiration automatique.
        }
        $key = $_ENV['STRIPE_SECRET_KEY'] ?? $_SERVER['STRIPE_SECRET_KEY'] ?? '';
        if (!$key) { return false; }
        $client = new \Stripe\StripeClient($key);
        try {
            try { $session = $client->checkout->sessions->retrieve($sessionId); }
            catch (\Stripe\Exception\InvalidRequestException $e) {
                // Seule une référence absente chez Stripe est une impossibilité historique connue.
                return $e->getStripeCode() === 'resource_missing' && !$storedIntent;
            }
            // Même un paiement incohérent avec nos montants interdit cette action.
            if ($session->id !== $sessionId || $session->status !== 'expired' || $session->payment_status !== 'unpaid') { return false; }
            $remoteIntent = $session->payment_intent ?? null;
            $remoteId = is_string($remoteIntent) ? $remoteIntent : ($remoteIntent->id ?? null);
            foreach (array_unique(array_filter([$remoteId, $storedIntent])) as $intentId) {
                $intent = $client->paymentIntents->retrieve($intentId);
                if ($intent->id !== $intentId || !in_array($intent->status, ['canceled', 'requires_payment_method'], true)) { return false; }
            }
            // Une session cohérente doit continuer à utiliser l'annulation normale.
            return (string) $session->client_reference_id !== (string) $order->getId()
                || $session->currency !== 'eur' || $session->amount_total !== $order->getTotalCents()
                || ($storedIntent && $remoteId && $storedIntent !== $remoteId);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return false; // Une panne réseau/API ne prouve jamais l'absence de paiement.
        }
    }

    public function cancel(Order $order, User $admin): void
    {
        if (!in_array('ROLE_ADMIN', $admin->getRoles(), true)) { throw new \DomainException('Action réservée aux administrateurs.'); }
        $this->em->wrapInTransaction(function () use ($order, $admin): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getState() === 4) { return; }
            if (!$this->isEligible($order)) {
                throw new \DomainException('Cette commande ne permet pas une annulation historique : paiement confirmé, en cours ou vérification non concluante.');
            }
            $this->orders->cancelUnpaid($order);
            $record = new CancellationRequest($order, self::REASON, 0);
            $record->resolve('accepted', $admin);
            $order->addCancellationRequest($record);
        });
    }
}
