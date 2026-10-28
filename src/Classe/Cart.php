<?php

namespace App\Classe;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class Cart
{
    public function __construct(private RequestStack $requestStack, private ProductRepository $products, private \App\Service\CommercialPricing $pricing) {}
    public function getPromoCode(): ?string { return $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession()->get('promo_code') : null; }
    public function setPromoCode(?string $code): void { $this->requestStack->getSession()->set('promo_code', $code); }
    public function getCommercialQuote(): array
    {
        $lines = $this->getCart();
        try { return $this->pricing->quote($lines, $this->getPromoCode()); }
        catch (\DomainException) {
            $this->setPromoCode(null);
            $this->requestStack->getSession()->getFlashBag()->add('warning', \App\Service\CommercialPricing::INVALID_CODE);
            return $this->pricing->quote($lines);
        }
    }

    public function add(Product $product, int $amount = 1): void
    {
        if ($amount < 1) {
            throw new \DomainException('Choisissez une quantité entière supérieure ou égale à 1.');
        }
        $lines = $this->getCart();
        $current = $lines[$product->getId()]['qty'] ?? 0;
        if (!$product->isActive() || $amount > $product->getStock() - $current) {
            throw new \DomainException('La quantité demandée dépasse le stock disponible.');
        }
        $quantity = $current + $amount;
        $quantities = $this->quantities($lines);
        $quantities[$product->getId()] = $quantity;
        $this->requestStack->getSession()->set('cart', $quantities);
    }

    public function decrease(int $id): void
    {
        $quantities = $this->quantities($this->getCart());
        if (isset($quantities[$id]) && $quantities[$id] > 1) { --$quantities[$id]; }
        $this->requestStack->getSession()->set('cart', $quantities);
    }

    public function delete(int $id): void
    {
        $quantities = $this->quantities($this->getCart());
        unset($quantities[$id]);
        $this->requestStack->getSession()->set('cart', $quantities);
    }

    public function fullQuantity(): int { return array_sum($this->quantities($this->getCart())); }

    public function getTotalWt(): float
    {
        return $this->getCommercialQuote()['totalCents'] / 100;
    }

    public function remove(): void { $this->requestStack->getSession()->remove('cart'); $this->setPromoCode(null); }

    /** Les objets Doctrine ne sont jamais conservés en session. */
    public function getCart(): array
    {
        if (!$this->requestStack->getCurrentRequest()) { return []; }
        $stored = $this->requestStack->getSession()->get('cart', []);
        if (!is_array($stored)) { $stored = []; }
        $lines = [];
        foreach ($stored as $id => $value) {
            // Compatibilité avec les anciennes sessions, sans réutiliser leurs prix.
            $quantity = is_array($value) ? ($value['qty'] ?? 0) : $value;
            if (!ctype_digit((string) $id) || !is_int($quantity) || $quantity < 1) { continue; }
            $product = $this->products->find((int) $id);
            if (!$product || !$product->isActive() || $product->getStock() < 1) { continue; }
            $lines[$id] = ['object' => $product, 'qty' => min($quantity, $product->getStock())];
        }
        $quantities = $this->quantities($lines);
        if ($stored !== $quantities) {
            $this->requestStack->getSession()->set('cart', $quantities);
            if ($stored) {
                $this->requestStack->getSession()->getFlashBag()->add('info', 'Votre panier a été actualisé selon les produits et les stocks disponibles.');
            }
        }
        return $lines;
    }

    private function quantities(array $lines): array
    {
        return array_map(static fn (array $line): int => $line['qty'], $lines);
    }
}
