<?php
namespace App\Service;

use App\Entity\{CommercialSettings, PromoCode};
use Doctrine\ORM\EntityManagerInterface;

/** Calculs en centimes, identiques dans le panier et sous verrou à la commande. */
class CommercialPricing
{
    public const INVALID_CODE = 'Ce code est invalide ou ne s’applique pas à votre sélection.';
    public function __construct(private EntityManagerInterface $em) {}
    public function settings(): CommercialSettings { return $this->em->find(CommercialSettings::class, 1) ?? new CommercialSettings(); }
    public function stockStatus(int $stock): string { return $stock <= 0 ? 'Rupture' : ($stock <= $this->settings()->getLowStockThreshold() ? 'Stock faible' : 'Disponible'); }
    public function freeShipping(int $netArticlesCents): bool
    {
        $settings = $this->settings();
        return $settings->getFreeShippingMode() === 'all' || ($settings->getFreeShippingMode() === 'threshold' && $netArticlesCents >= $settings->getFreeShippingThresholdCents());
    }
    public function quote(array $lines, ?string $code = null, bool $lock = false): array
    {
        $now = new \DateTimeImmutable();
        $subtotal = 0; $eligible = 0; $amounts = []; $units = []; $promoted = [];
        foreach ($lines as $key => $line) {
            if (!is_int($line['qty']) || $line['qty'] < 1) { throw new \DomainException('Quantité invalide.'); }
            $units[$key] = (int) round($line['object']->getPriceWt($now) * 100);
            $promoted[$key] = $line['object']->isPromotionActive($now);
            $amounts[$key] = $units[$key] * $line['qty'];
            $subtotal += $amounts[$key];
        }
        $coupon = null; $discount = 0;
        if ($code !== null && $code !== '') {
            $coupon = $this->em->getRepository(PromoCode::class)->findOneBy(['code' => strtoupper(trim($code))]);
            if ($coupon && $lock) { $this->em->refresh($coupon, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE); }
            if (!$coupon || !$coupon->isAvailable($now) || $coupon->getValue() < 1
                || ($coupon->getType() === 'percent' && $coupon->getValue() > 100)
                || !in_array($coupon->getType(), ['percent', 'fixed'], true)
                || $subtotal < ($coupon->getMinimumCents() ?? 0)) { throw new \DomainException(self::INVALID_CODE); }
            foreach ($lines as $key => $line) {
                if ($coupon->isStackable() || !$promoted[$key]) { $eligible += $amounts[$key]; }
            }
            $discount = min($eligible, $coupon->getType() === 'percent' ? (int) round($eligible * $coupon->getValue() / 100) : $coupon->getValue());
            if ($discount <= 0) { throw new \DomainException(self::INVALID_CODE); }
        }
        // Répartition proportionnelle séquentielle : somme exacte, jamais de ligne négative.
        $allocated = []; $remaining = $discount; $base = $eligible;
        foreach ($lines as $key => $line) {
            $value = 0;
            if ($coupon && ($coupon->isStackable() || !$promoted[$key]) && $base > 0) {
                $value = min($amounts[$key], (int) round($remaining * $amounts[$key] / $base));
                $base -= $amounts[$key]; $remaining -= $value;
            }
            $allocated[$key] = $value;
        }
        return ['subtotalCents' => $subtotal, 'discountCents' => $discount, 'totalCents' => max(0, $subtotal - $discount),
            'discounts' => $allocated, 'unitCents' => $units, 'coupon' => $coupon, 'freeShipping' => $this->freeShipping(max(0, $subtotal - $discount))];
    }
}
