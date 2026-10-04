<?php
declare(strict_types=1);
namespace App\Service;

/** Sélection commerciale indépendante du devis et de son montant. */
final class SendcloudMethodPolicy
{
    public function __construct(private array $rules = [
        'relay' => ['carriers' => ['mondial_relay', 'colissimo', 'chronopost'], 'methods' => ['*'], 'preferred_methods' => []],
        'standard' => ['carriers' => ['colissimo'], 'methods' => ['*'], 'preferred_methods' => []],
        'express' => ['carriers' => ['chronopost'], 'methods' => ['*'], 'preferred_methods' => []],
    ]) {}

    public function category(array $option): ?string
    {
        $features = $option['functionalities'] ?? [];
        if (($features['returns'] ?? false) || (isset($features['form_factor']) && $features['form_factor'] !== 'parcel')) { return null; }
        // Défense supplémentaire lorsque les caractéristiques sont absentes/incomplètes.
        if (preg_match('/letter|lettre|mailbox|unstamped|envelope|enveloppe/i', ($option['code'] ?? '').' '.($option['name'] ?? '').' '.($option['product']['code'] ?? ''))) { return null; }
        $point = ($option['requirements']['is_service_point_required'] ?? false) === true;
        $lastMile = $features['last_mile'] ?? null;
        if ($lastMile !== null && !in_array($lastMile, ['home_delivery', 'service_point', 'locker', 'locker_or_service_point'], true)) { return null; }
        // Un relais sans exigence d’identifiant ne peut pas utiliser notre parcours sécurisé.
        if (in_array($lastMile, ['service_point', 'locker', 'locker_or_service_point'], true) && !$point) { return null; }
        if ($point && $lastMile === 'home_delivery') { return null; }
        $express = ($features['premium'] ?? false) === true
            || in_array($features['delivery_deadline'] ?? null, ['sameday', 'nextday', 'within_24h'], true);
        foreach (['relay', 'standard', 'express'] as $category) {
            $rule = $this->rules[$category] ?? [];
            if (!in_array($option['carrier']['code'] ?? '', $rule['carriers'] ?? [], true)) { continue; }
            if (($category === 'relay') !== $point || ($category === 'standard' && $express)) { continue; }
            if ($this->matches($option['code'] ?? '', $rule['methods'] ?? [])) { return $category; }
        }
        return null;
    }

    public function shortlist(array $offers): array
    {
        $result = [];
        foreach (['relay', 'standard', 'express'] as $category) {
            $group = array_filter($offers, static fn ($offer) => $offer['category'] === $category);
            uasort($group, function ($a, $b) use ($category) {
                $preferred = $this->rules[$category]['preferred_methods'] ?? [];
                $rank = fn ($offer) => $this->matches($offer['code'], $preferred) ? 0 : 1;
                return [$rank($a), $a['price_cents'], $a['code'], $a['key']] <=> [$rank($b), $b['price_cents'], $b['code'], $b['key']];
            });
            // Un choix domicile par catégorie, un choix relais par transporteur.
            $seen = [];
            foreach ($group as $key => $offer) {
                $bucket = $category === 'relay' ? $offer['carrier_code'] : $category;
                if (isset($seen[$bucket])) { continue; }
                $seen[$bucket] = true; $result[$key] = $offer;
            }
        }
        return $result;
    }

    private function matches(string $code, array $patterns): bool
    {
        foreach ($patterns as $pattern) { if (fnmatch($pattern, $code)) { return true; } }
        return false;
    }
}
