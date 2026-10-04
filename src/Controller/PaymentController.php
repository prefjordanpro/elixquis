<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\{OrderManager, StripePayment, StripeRefund};
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    #[Route('/commande/paiement/{id_order}', name: 'app_payment', methods: ['POST'])]
    public function index(int $id_order, Request $request, OrderRepository $repository, StripePayment $payment, LoggerInterface $logger): Response
    {
        $order = $repository->findOneBy(['id' => $id_order, 'user' => $this->getUser()]);
        if (!$order) { throw $this->createNotFoundException('Commande introuvable.'); }
        if (!$this->isCsrfTokenValid('payment_'.$id_order, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($request->request->get('age_confirmed') !== '1') {
            $this->addFlash('warning', 'Vous devez confirmer avoir au moins 18 ans.');
        } else {
            try { return $this->redirect($payment->checkout($order), 303); }
            catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage()); }
            catch (\Stripe\Exception\ApiErrorException $e) {
                $logger->error('Échec du paiement Stripe.', ['order' => $id_order, 'type' => $e::class]);
                $this->addFlash('danger', 'Le service de paiement est indisponible. Réessayez plus tard.');
            }
        }
        return $this->redirectToRoute('app_account_order', ['id_order' => $id_order]);
    }

    #[Route('/commande/merci/{stripe_session_id}', name: 'app_payment_success', methods: ['GET'])]
    public function success(string $stripe_session_id, OrderRepository $repository, StripePayment $payment): Response
    {
        $order = $repository->findOneBy(['stripe_session_id' => $stripe_session_id, 'user' => $this->getUser()]);
        if (!$order) { throw $this->createNotFoundException('Commande introuvable.'); }
        try { $payment->verify($order); }
        catch (\DomainException|\Stripe\Exception\ApiErrorException $e) {
            $this->addFlash('warning', 'Le paiement n’est pas encore confirmé. Consultez le statut de votre commande.');
            return $this->redirectToRoute('app_account_order', ['id_order' => $order->getId()]);
        }
        return $this->render('payment/success.html.twig', ['order' => $order]);
    }

    #[Route('/paiement/webhook', name: 'app_payment_webhook', methods: ['POST'])]
    public function webhook(Request $request, OrderRepository $repository, OrderManager $orders, StripeRefund $refunds, LoggerInterface $logger): Response
    {
        $secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? '';
        if (!$secret) { return new Response('Paiement indisponible.', 503); }
        try {
            $event = \Stripe\Webhook::constructEvent($request->getContent(), $request->headers->get('Stripe-Signature', ''), $secret);
        } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $e) {
            return new Response('Signature invalide.', 400);
        }
        if (in_array($event->type, ['refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated'], true)) {
            $refund = $event->data->object;
            $order = $repository->findOneBy(['stripeRefundId' => $refund->id]);
            if (!$order && is_string($refund->payment_intent)) {
                $order = $repository->findOneBy(['stripePaymentIntentId' => $refund->payment_intent]);
            }
            if (!$order && isset($refund->metadata->order_id) && ctype_digit((string) $refund->metadata->order_id)) {
                $order = $repository->find((int) $refund->metadata->order_id);
            }
            if (!$order) { return new Response('OK'); }
            try { $refunds->synchronize($order, $refund->id); }
            catch (\DomainException $e) {
                $logger->error('Remboursement Stripe incompatible.', ['order' => $order->getId(), 'refund' => $refund->id]);
                return new Response('Remboursement incompatible avec la commande.', 409);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $logger->error('Synchronisation Stripe indisponible.', ['order' => $order->getId(), 'refund' => $refund->id]);
                return new Response('Stripe indisponible, réessayer.', 503);
            }
            return new Response('OK');
        }
        if (!in_array($event->type, ['checkout.session.completed', 'checkout.session.expired'], true)) { return new Response('OK'); }
        $session = $event->data->object;
        $order = $repository->findOneBy(['stripe_session_id' => $session->id]);
        if (!$order) { return new Response('Commande inconnue.', 409); }
        try {
            if ($event->type === 'checkout.session.completed' && $session->payment_status === 'paid') {
                $orders->confirmPayment($order, $session);
            } elseif ($event->type === 'checkout.session.expired' && $order->getState() === 0) {
                $orders->cancelUnpaid($order);
            }
        } catch (\DomainException $e) { return new Response('Commande incompatible avec le paiement.', 409); }
        return new Response('OK');
    }
}
