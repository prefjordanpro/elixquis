<?php

namespace App\Controller\Account;

use App\Repository\OrderRepository;
use App\Service\StripePayment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/compte/commande/{id_order}/annuler', name: 'app_account_order_cancel', methods: ['POST'])]
    public function cancel(int $id_order, Request $request, OrderRepository $repository, StripePayment $payment): Response
    {
        $order = $repository->findOneBy(['id' => $id_order, 'user' => $this->getUser()]);
        if (!$order) { throw $this->createNotFoundException('Commande introuvable.'); }
        if (!$this->isCsrfTokenValid('cancel_'.$id_order, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        try { $payment->cancel($order); $this->addFlash('success', 'Commande annulée et stock libéré.'); }
        catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage()); }
        catch (\Stripe\Exception\ApiErrorException $e) { $this->addFlash('warning', 'Impossible de confirmer l’annulation du paiement. Réessayez plus tard.'); }
        return $this->redirectToRoute('app_account_order', ['id_order' => $id_order]);
    }
    #[Route('/compte/commande/{id_order}', name: 'app_account_order')]
    public function index($id_order, OrderRepository $orderRepository): Response
    {
        $order = $orderRepository->findOneBy([
            'id' => $id_order,
            'user' => $this->getUser()
        ]);

        if (!$order) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('account/order/index.html.twig', [
            'order' => $order,
        ]);
    }

}
