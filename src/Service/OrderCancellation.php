<?php

namespace App\Service;

use App\Entity\{CancellationRequest, Order, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OrderCancellation
{
    public function __construct(private EntityManagerInterface $em, private StripePayment $payment, private LoggerInterface $logger) {}

    public function request(Order $order, User $owner, ?string $reason): string
    {
        return $this->em->wrapInTransaction(function () use ($order, $owner, $reason): string {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getUser()->getId() !== $owner->getId()) { throw new \DomainException('Commande introuvable.'); }
            if ($order->getState() === 7) { return 'pending'; }
            if ($order->getState() === 4 && $order->getLatestCancellationRequest()?->getPreviousState() === 0) { return 'cancelled'; }
            if (!$order->canRequestCancellation()) { throw new \DomainException('Cette commande ne peut plus être annulée en ligne.'); }
            $reason = trim($reason ?? '');
            if (mb_strlen($reason) > 1000) { throw new \DomainException('La raison doit contenir au maximum 1 000 caractères.'); }
            $request = new CancellationRequest($order, $reason === '' ? null : $reason, $order->getState());
            $order->addCancellationRequest($request); $this->em->persist($request); $this->em->flush();
            if ($order->getState() === 0) {
                $this->payment->cancel($order);
                $request->resolve('accepted');
                $result = 'cancelled';
            } else {
                $order->setState(7);
                $result = 'requested';
            }
            $this->logger->info('Demande d’annulation client enregistrée.', ['order' => $order->getId(), 'request' => $request->getId(), 'decision' => $request->getDecision()]);
            return $result;
        });
    }

    /** L’acceptation annule atomiquement ; le remboursement est ensuite confirmé séparément par Stripe. */
    public function resolve(Order $order, int $requestId, bool $accept, User $admin): void
    {
        $this->em->wrapInTransaction(function () use ($order, $requestId, $accept, $admin): void {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $request = $this->em->find(CancellationRequest::class, $requestId);
            if ($request) { $this->em->refresh($request); }
            if (!$request || $request->getOrder()->getId() !== $order->getId()) { throw new \DomainException('Demande d’annulation introuvable.'); }
            $decision = $accept ? 'accepted' : 'refused';
            if ($request->getDecision() === $decision) { return; }
            if ($request->getDecision() !== 'pending' || $order->getState() !== 7
                || !in_array($request->getPreviousState(), [1, 2], true)) {
                throw new \DomainException('Cette demande d’annulation ne peut plus être traitée.');
            }
            if (!$accept && in_array($order->getStripeRefundStatus(), ['pending', 'requires_action'], true)) {
                throw new \DomainException('Un remboursement est déjà en cours dans Stripe. La demande ne peut plus être refusée.');
            }
            $order->setState($request->getPreviousState()); $this->em->flush();
            if ($accept) { $this->payment->cancelForAdmin($order); }
            $request->resolve($decision, $admin);
            $this->logger->info('Demande d’annulation résolue.', ['order' => $order->getId(), 'request' => $requestId, 'decision' => $decision, 'admin' => $admin->getId()]);
        });
    }
}
