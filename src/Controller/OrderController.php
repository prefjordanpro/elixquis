<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Address;
use App\Entity\Carrier;
use App\Form\OrderType;
use App\Classe\Cart; // <— importe ton service panier
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/commande/livraison', name: 'app_order', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $addresses = $user->getAddresses();
        if ($addresses->count() === 0) {
            return $this->redirectToRoute('app_account_address_form');
        }

        $form = $this->createForm(OrderType::class, null, [
            'addresses' => $addresses,
            'action'    => $this->generateUrl('app_order_summary'),
        ]);

        return $this->render('order/index.html.twig', [
            'deliverForm' => $form->createView(),
        ]);
    }

    #[Route('/commande/recapitulatif', name: 'app_order_summary', methods: ['POST'])]
    public function add(Request $request, Cart $cart): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Recrée le form pour binder la soumission
        $form = $this->createForm(OrderType::class, null, [
            'addresses' => $user->getAddresses(),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->redirectToRoute('app_order');
        }

        /** @var Address $address */
        $address = $form->get('addresses')->getData();
        /** @var Carrier $carrier */
        $carrier = $form->get('carriers')->getData();

        // Défense en profondeur : l'adresse doit appartenir au user
        if (!$address || $address->getUser() !== $user) {
            $this->addFlash('danger', 'Adresse invalide.');
            return $this->redirectToRoute('app_order');
        }

        // Total produits via ton service panier
        $totalWt = $cart->getTotalWt(); // utilise ta méthode existante
        $grandTotal = $totalWt + (float) ($carrier?->getPrice() ?? 0);

        return $this->render('order/summary.html.twig', [
            'address'     => $address,
            'carrier'     => $carrier,
            'totalWt'     => $totalWt,
            'grandTotal'  => $grandTotal,
        ]);
    }
}
