<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class PaymentController extends AbstractController
{
    #[Route('/commande/paiement/{id_order}', name: 'app_payment')]
    public function index($id_order, OrderRepository $orderRepository): Response
    {   
        $stripe = new \Stripe\StripeClient($_ENV['STRIPE_SECRET_KEY']);
        $YOUR_DOMAIN = 'http://127.0.0.1:8000';

        $order = $orderRepository->findOneBy([
            'id' => $id_order,
            'user' => $this->getUser()
        ]);

        if (!$order) {
            return $this->redirectToRoute('app_home');
        }

        $products_for_stripe = [];

        foreach ($order->getOrderDetails() as $product) {
            $unitTtc = $product->getProductPrice(); // prix unitaire TTC
            $qty     = (int) $product->getProductQuantity();

            $products_for_stripe[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => (int) round($unitTtc * 100),
                    'tax_behavior' => 'inclusive',            // ✅ prix TTC
                    'product_data' => [
                        'name' => $product->getProductName(),
                        'images' => [$YOUR_DOMAIN . '/uploads/' . $product->getProductIllustration()],
                    ],
                ],
                'quantity' => max(1, $qty),
            ];
        }

        $products_for_stripe[] = [
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => number_format($order->getCarrierPrice() * 100, 0, '', ''),
                'product_data' => [
                    'name' => 'Transporteur : '.$order->getCarrierName(),
                ]
            ],
            'quantity' => 1,
        ];


        $checkout_session = $stripe->checkout->sessions->create([
            'customer_email' => $this->getUser()->getEmail(),
            'line_items' => $products_for_stripe, // ✅ on passe directement le tableau
            'mode' => 'payment',
            'success_url' => $YOUR_DOMAIN . '/success.html',
            'cancel_url'  => $YOUR_DOMAIN . '/cancel.html',
            'automatic_tax' => ['enabled' => false],
        ]);

        return $this->redirect($checkout_session->url);
    }
}

