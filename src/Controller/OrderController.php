<?php

namespace App\Controller;

use App\Classe\Cart;
use App\Entity\User;
use App\Form\OrderType;
use App\Repository\OrderRepository;
use App\Service\OrderManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/commande/livraison', name: 'app_order', methods: ['GET'])]
    public function index(Cart $cart, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) { return $this->redirectToRoute('app_login'); }
        if (!$cart->getCart()) { return $this->redirectToRoute('app_cart'); }
        if ($user->getAddresses()->isEmpty()) { return $this->redirectToRoute('app_account_address_form'); }
        $request->getSession()->remove('current_order_id');
        $form = $this->createForm(OrderType::class, null, ['addresses' => $user->getAddresses(),
            'action' => $this->generateUrl('app_order_summary')]);
        return $this->render('order/index.html.twig', ['deliverForm' => $form->createView()]);
    }

    #[Route('/commande/recapitulatif', name: 'app_order_summary', methods: ['POST'])]
    public function add(Request $request, Cart $cart, OrderManager $orders, OrderRepository $repository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) { return $this->redirectToRoute('app_login'); }
        $form = $this->createForm(OrderType::class, null, ['addresses' => $user->getAddresses()]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('warning', 'Vérifiez votre adresse et votre transporteur.');
            return $this->redirectToRoute('app_order');
        }
        // Un double envoi du formulaire renvoie vers la commande déjà créée.
        $session = $request->getSession();
        if ($id = $session->get('current_order_id')) {
            $existing = $repository->findOneBy(['id' => $id, 'user' => $user, 'state' => 0]);
            if ($existing) { return $this->redirectToRoute('app_account_order', ['id_order' => $id]); }
        }
        $lines = $cart->getCart();
        try {
            $order = $orders->create($user, $form->get('addresses')->getData(), $form->get('carriers')->getData(), $lines);
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('app_cart');
        }
        $session->set('current_order_id', $order->getId());
        $cart->remove();
        return $this->redirectToRoute('app_account_order', ['id_order' => $order->getId()]);
    }
}
