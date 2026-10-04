<?php

namespace App\Classe;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class Cart
{
    public function __construct(private RequestStack $requestStack, private ProductRepository $products) {}

    public function add(Product $product): void
    {
        $lines = $this->getCart();
        $quantity = ($lines[$product->getId()]['qty'] ?? 0) + 1;
        if (!$product->isActive() || $quantity > $product->getStock()) {
            throw new \DomainException('La quantité demandée dépasse le stock disponible.');
        }
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
        $cents = 0;
        foreach ($this->getCart() as $line) {
            $cents += (int) round($line['object']->getPriceWt() * 100) * $line['qty'];
        }
        return $cents / 100;
    }

    public function remove(): void { $this->requestStack->getSession()->remove('cart'); }

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
