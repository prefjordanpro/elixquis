<?php

namespace App\Twig;

use App\Classe\Cart;
use App\Repository\CategoryRepository;
use App\Repository\HeaderRepository; // ⬅️ ajouter
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

class AppExtensions extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private Cart $cart,
        private HeaderRepository $headerRepository // ⬅️ injecter
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('price', [$this, 'formatPrice']),
        ];
    }

    public function formatPrice(mixed $number): string
    {
        // + robuste : décimales, séparateurs FR
        return number_format((float) $number, 2, ',', ' ') . ' €';
    }

    public function getGlobals(): array
    {
        return [
            'allCategories'     => $this->categoryRepository->findAll(),
            'fullCartQuantity'  => $this->cart->fullQuantity(),
            // Adapte le tri/filtre si tu as isActive/position
            'siteHeaders'       => $this->headerRepository->findBy([], ['id' => 'ASC']),
            // ex : findBy(['isActive' => true], ['position' => 'ASC'])
        ];
    }
}
