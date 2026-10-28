<?php

namespace App\Twig;

use App\Classe\Cart;
use App\Repository\CategoryRepository;
use App\Repository\HeaderRepository;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

class AppExtensions extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private Cart $cart,
        private HeaderRepository $headerRepository,
        private \App\Service\CompanySettings $company,
        private \App\Service\HomeContent $homeContent
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
            'company' => $this->company->details(),
            'homeContent' => $this->homeContent->get(),
            'allCategories'     => $this->categoryRepository->findAll(),
            'fullCartQuantity'  => $this->cart->fullQuantity(),
            'siteHeaders'       => $this->headerRepository->findBy(['active' => true], ['position' => 'ASC', 'id' => 'ASC']),
        ];
    }
}
