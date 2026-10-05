<?php

namespace App\Controller;

use App\Classe\Cart;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
    #[Route('/mon-panier/{motif}', name: 'app_cart', defaults: ['motif' => null], methods: ['GET'])]
    public function index(Cart $cart, ?string $motif): Response
    {
        if ($motif === 'annulation') {
            $this->addFlash('info', 'Paiement interrompu. Retrouvez votre commande en attente dans votre compte.');
        }
        return $this->render('cart/index.html.twig', ['cart' => $cart->getCart(), 'totalWt' => $cart->getTotalWt()]);
    }

    #[Route('/cart/add/{id}', name: 'app_cart_add', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function add(int $id, Cart $cart, ProductRepository $products, Request $request): Response
    {
        $this->validateToken($request);
        $product = $products->find($id);
        if (!$product || !$product->isActive()) { throw $this->createNotFoundException('Produit indisponible.'); }
        try {
            $parameters = $request->request->all();
            $rawQuantity = array_key_exists('quantity', $parameters) ? $parameters['quantity'] : '1';
            $quantity = (is_string($rawQuantity) || is_int($rawQuantity)) && preg_match('/^[1-9][0-9]*$/D', (string) $rawQuantity)
                ? filter_var($rawQuantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($quantity === false) {
                throw new \DomainException('Choisissez une quantité entière supérieure ou égale à 1.');
            }
            $cart->add($product, $quantity);
            $this->addFlash('success', 'Produit ajouté au panier.');
        } catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('app_cart');
    }

    #[Route('/cart/decrease/{id}', name: 'app_cart_decrease', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function decrease(int $id, Cart $cart, Request $request): Response
    {
        $this->validateToken($request);
        $cart->decrease($id);
        return $this->redirectToRoute('app_cart');
    }

    #[Route('/cart/delete/{id}', name: 'app_cart_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function delete(int $id, Cart $cart, Request $request): Response
    {
        $this->validateToken($request);
        $cart->delete($id);
        return $this->redirectToRoute('app_cart');
    }

    #[Route('/cart/remove', name: 'app_cart_remove', methods: ['POST'])]
    public function remove(Cart $cart, Request $request): Response
    {
        $this->validateToken($request);
        $cart->remove();
        return $this->redirectToRoute('app_cart');
    }

    private function validateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('cart', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
