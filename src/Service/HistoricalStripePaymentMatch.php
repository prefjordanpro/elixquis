<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Order;

/** Profil antérieur au checkout sécurisé introduit le 04/10/2026, jamais un délai glissant. */
final class HistoricalStripePaymentMatch
{
    public static function isCandidate(Order $order, object $session): bool
    {
        return $order->getCreatedAt() !== null
            && $order->getCreatedAt() < new \DateTimeImmutable('2026-10-04T00:00:00+02:00')
            && (($session->client_reference_id ?? null) === null || $session->client_reference_id === '');
    }

    public static function matches(Order $order, object $session, object $intent): bool
    {
        $remoteIntent = $session->payment_intent ?? null;
        $intentId = is_string($remoteIntent) ? $remoteIntent : ($remoteIntent->id ?? null);
        if (!self::isCandidate($order, $session) || !$order->getStripeSessionId()
            || ($session->id ?? null) !== $order->getStripeSessionId()
            || ($session->status ?? null) !== 'complete' || ($session->payment_status ?? null) !== 'paid'
            || ($session->currency ?? null) !== 'eur' || ($session->amount_total ?? null) !== $order->getTotalCents()
            || !$intentId || ($intent->id ?? null) !== $intentId || ($intent->status ?? null) !== 'succeeded'
            || ($intent->currency ?? null) !== 'eur' || ($intent->amount_received ?? null) !== $order->getTotalCents()
            || ($order->getStripePaymentIntentId() && $order->getStripePaymentIntentId() !== $intentId)) { return false; }
        foreach ([$session, $intent] as $source) {
            $metadata = $source->metadata ?? null;
            foreach (['order_id' => (string) $order->getId(), 'order_reference' => $order->getReference()] as $key => $expected) {
                $value = is_array($metadata) ? ($metadata[$key] ?? null) : ($metadata->$key ?? null);
                if ($value !== null && (string) $value !== $expected) { return false; }
            }
        }
        return true;
    }
}
