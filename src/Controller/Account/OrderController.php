<?php

namespace App\Controller\Account;

use App\Repository\OrderRepository;
use App\Service\OrderCancellation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_USER')]
final class OrderController extends AbstractController
{
    #[Route('/compte/commande/{id_order}/annuler', name: 'app_account_order_cancel', methods: ['POST'])]
    public function cancel(int $id_order, Request $request, OrderRepository $repository, OrderCancellation $cancellations): Response
    {
        $order = $repository->findOneBy(['id' => $id_order, 'user' => $this->getUser()]);
        if (!$order) { throw $this->createNotFoundException('Commande introuvable.'); }
        if (!$this->isCsrfTokenValid('cancel_'.$id_order, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        try {
            $result = $cancellations->request($order, $this->getUser(), $request->request->getString('reason'));
            if ($result === 'cancelled') { $this->addFlash('success', 'Votre commande a été annulée. Aucun paiement n’avait été encaissé.'); }
            elseif ($result === 'pending') { $this->addFlash('info', 'Votre demande est en cours de traitement.'); }
            else { $this->addFlash('success', 'Votre demande d’annulation a bien été enregistrée.'); }
        }
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
