<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Order;
use App\Entity\Address;
use App\Entity\Carrier;
use App\Form\OrderType;
use App\Entity\OrderDetail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Classe\Cart; // <— importe ton service panier
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

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
    public function add(Request $request, Cart $cart, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Recrée le form et traite la soumission
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

        if (!$address || $address->getUser() !== $user) {
            $this->addFlash('danger', 'Adresse invalide.');
            return $this->redirectToRoute('app_order');
        }

        // === Création et persistance de la commande ===
        $delivery = sprintf(
            "%s %s\n%s\n%s %s — %s",
            $address->getFirstname(),
            $address->getLastname(),
            $address->getAddress(),
            $address->getPostal(),
            $address->getCity(),
            $address->getCountry()
        );

        $order = (new Order())
            ->setUser($this->getUser())
            ->setCreatedAt(new \DateTime())
            ->setCarrierName($carrier->getName())
            ->setCarrierPrice((float) $carrier->getPrice())
            ->setDelivery($delivery)
            ->setState(0); // 1 = NON PAYÉ

        $em->persist($order);

        foreach ($cart->getCart() as $line) {
            $product = $line['object'];
            $qty     = (int) $line['qty'];

            // Prix TTC unitaire (adapte selon ton entité Product)
            $unitTtc =
                (method_exists($product, 'getPriceWt') ? (float) $product->getPriceWt() :
                (property_exists($product, 'pricewt')   ? (float) $product->pricewt :
                (method_exists($product, 'getPrice')    ? (float) $product->getPrice() : 0.0)));

            $tva = method_exists($product, 'getTva') ? (float) $product->getTva() : 20.0;

            $detail = (new OrderDetail())
                ->setMyOrder($order)
                ->setProductName($product->getName())
                ->setProductIllustration(
                    method_exists($product, 'getIllustration') ? (string) $product->getIllustration() : ''
                )
                ->setProductQuantity($qty)
                ->setProductPrice($unitTtc) // TTC unitaire
                ->setProductTva($tva);

            $em->persist($detail);
        }

        $em->flush();

        // Totaux pour l’affichage du récap
        $totalWt   = $cart->getTotalWt();
        $grandTotal = $totalWt + (float) $carrier->getPrice();

        // (Optionnel) garde-fou anti-refresh : mémorise l’ID en session
        $request->getSession()->set('current_order_id', $order->getId());

        return $this->render('order/summary.html.twig', [
            'address'     => $address,
            'carrier'     => $carrier,
            'totalWt'     => $totalWt,
            'grandTotal'  => $grandTotal,
            'cart'        => $cart->getCart(),
            'order'       => $order, // 👈 on passe l’objet pour le bouton “Payer”
        ]);
    }
}
