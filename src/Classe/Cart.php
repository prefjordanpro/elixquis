<?php

namespace App\Classe;

use Symfony\Component\HttpFoundation\RequestStack;

class Cart
{
    public function __construct(private RequestStack $requestStack)
    {
        
    }

    /*
    *add() 
    fonction permettant l'ajout d'un produit au panier
    */


    public function add($product)
    {
        //Appeler la session de symfony
        $cart = $this->getCart();

        //Ajouter une quantité +1
        if(isset ($cart [$product->getId()])) {
            $cart[$product->getId()] = [
            'object' => $product,
            'qty' => $cart[$product->getId()] ['qty'] + 1
            ];
        } else {
            $cart[$product->getId()] = [
            'object' => $product,
            'qty' => 1
            ];
        }


        //Créer ma session cart
        $this->requestStack->getSession()->set('cart', $cart);
    }

    /*
    *decrease() 
    fonction permettant la suppression d'une quantité d'un produit au panier
    */

    public function decrease($id)
    {
        $cart = $this->getCart();

        if($cart[$id]['qty'] > 1 ){
            $cart[$id]['qty'] = $cart[$id]['qty'] - 1;
        } else {
            unset($cart[$id]);
        }

        $this->requestStack->getSession()->set('cart', $cart);

    }

    /*
    *fullQuantity() 
    fonction retournant le nombre total de produit au panier
    */

    public function fullQuantity()
    {
        $cart = $this->getCart();
        $quantity = 0;

        if(!isset($cart)){
            return $quantity;
        }

        foreach($cart as $product){
            $quantity = $quantity + $product['qty'];
        }
        return $quantity;
    }


    /*
    *getTotalWt() 
    fonction retournant le prix total des produits du panier
    */

    public function getTotalWt()
    {
        $cart = $this->getCart();
        $price = 0;

        if(!isset($cart)){
            return $price;
        }

        foreach($cart as $product){
            $price = $price + ($product['object']->getPriceWt() * $product['qty']);
        }
        return $price;
    }

    /*
    *remove() 
    fonction permettant de supprimer totalement le panier
    */

    public function remove()
    {
        return $this->requestStack->getSession()->remove('cart');
    }

    /*
    *getCart() 
    fonction retournant le panier
    */

    public function getCart()
    {
        return $this->requestStack->getSession()->get('cart');
    }
}