<?php
namespace App\Twig;

use App\Service\{Homepage, CommercialPricing};
use App\Classe\Cart;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CommercialExtension extends AbstractExtension
{
    public function __construct(private Homepage $homepage, private CommercialPricing $pricing, private Cart $cart) {}
    public function getFunctions(): array
    {
        return [new TwigFunction('home_content', $this->homepage->content(...)),
            new TwigFunction('commercial_cart', $this->cart->getCommercialQuote(...)),
            new TwigFunction('stock_status', $this->pricing->stockStatus(...))];
    }
}
