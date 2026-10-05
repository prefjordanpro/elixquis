<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Emballage;
use App\Repository\EmballageRepository;

/** Recherche déterministe, sans poids produit ni formats de carton codés en dur. */
final class Colisage
{
    public function __construct(private EmballageRepository $emballages, private ShippingConfiguration $configuration) {}

    public function signature(): string
    {
        $rows = $this->emballages->findBy([], ['id' => 'ASC']);
        return hash('sha256', json_encode(array_map(fn (Emballage $e) => [$e->isActif(), $e->snapshot()], $rows), JSON_THROW_ON_ERROR));
    }

    public function plan(array $lines): array
    {
        $configured = $this->emballages->findBy([], ['id' => 'ASC']);
        if (!$configured) {
            // Transition : profil historique uniquement tant qu'aucun emballage n'a été configuré.
            $parcel = $this->configuration->parcel($lines);
            $content = $this->contenu($lines);
            $productsWeight = array_sum(array_column($content, 'poids_total_g'));
            $weight = (int) round((float) $parcel['weight']['value'] * 1000);
            return [['emballage' => ['id' => null, 'nom' => 'Emballage historique (.env)', 'capacite' => $this->configuration->legacyCapacity(),
                'poids_emballage_g' => $weight - $productsWeight, 'dimensions' => $parcel['dimensions'], 'poids_max_g' => null, 'priorite' => 0],
                'contenu' => $content, 'nombre_unites' => array_sum(array_column($content, 'quantite')), 'poids_produits_g' => $productsWeight, 'poids_total_g' => $weight]];
        }
        return $this->calculer($lines, $configured);
    }

    /** Public pour vérifier l'algorithme indépendamment de la base et des API. */
    public function calculer(array $lines, array $configured): array
    {
        $content = $this->contenu($lines);
        $boxes = array_values(array_filter($configured, fn (Emballage $e) => $e->isActif() && $e->estComplet()));
        usort($boxes, fn ($a, $b) => [-$a->getCapacite(), $a->getPriorite(), $a->getId(), $a->getNom()] <=> [-$b->getCapacite(), $b->getPriorite(), $b->getId(), $b->getNom()]);
        if (!$boxes) { throw new \DomainException('Aucun emballage actif et complet ne permet cette livraison. Contactez la boutique.'); }
        // Références lourdes en premier ; les combinaisons explorent aussi les mélanges lourd/léger.
        usort($content, fn ($a, $b) => [-$a['poids_unitaire_g'], $a['produit_id'], $a['nom']] <=> [-$b['poids_unitaire_g'], $b['produit_id'], $b['nom']]);
        $counts = array_column($content, 'quantite'); $work = 0;
        // Les exemples métier exigent des cartons remplis (4 => 3+1, pas un carton 6).
        // En l'absence d'une telle solution, autoriser des places libres plutôt que bloquer un panier compatible.
        foreach ([true, false] as $full) {
            $memo = [];
            $solve = function (array $remaining) use (&$solve, &$memo, &$work, $boxes, $content, $full): ?array {
                if (!array_sum($remaining)) { return []; }
                $state = implode(',', $remaining);
                if (array_key_exists($state, $memo)) { return $memo[$state]; }
                if (++$work > 100000) { throw new \DomainException('Ce panier nécessite une vérification de colisage par la boutique.'); }
                $seed = array_key_first(array_filter($remaining)); $best = null; $bestScore = null;
                foreach ($boxes as $box) {
                    $capacity = $box->getCapacite(); $limit = ($box->getPoidsMaxGrammes() ?? PHP_INT_MAX) - $box->getPoidsVideGrammes();
                    if ($full && $capacity > array_sum($remaining)) { continue; }
                    $enumerate = function (int $i, array $take, int $units, int $grams) use (&$enumerate, &$solve, &$best, &$bestScore, &$work, $remaining, $seed, $capacity, $limit, $box, $content, $full): void {
                        if (++$work > 100000) { throw new \DomainException('Ce panier nécessite une vérification de colisage par la boutique.'); }
                        if ($i === count($remaining)) {
                            if (!$units || ($full && $units !== $capacity)) { return; }
                            $rest = array_map(fn ($n, $t) => $n - $t, $remaining, $take);
                            $tail = $solve($rest);
                            if ($tail === null) { return; }
                            $items = [];
                            foreach ($take as $index => $qty) {
                                if ($qty) { $items[] = array_replace($content[$index], ['quantite' => $qty, 'poids_total_g' => $qty * $content[$index]['poids_unitaire_g']]); }
                            }
                            $candidate = [['emballage' => $box->snapshot(), 'contenu' => $items, 'nombre_unites' => $units,
                                'poids_produits_g' => $grams, 'poids_total_g' => $grams + $box->getPoidsVideGrammes()], ...$tail];
                            usort($candidate, fn ($a, $b) => [-$a['emballage']['capacite'], $a['emballage']['priorite'], $a['emballage']['id'], json_encode($a['contenu'])]
                                <=> [-$b['emballage']['capacite'], $b['emballage']['priorite'], $b['emballage']['id'], json_encode($b['contenu'])]);
                            $score = [count($candidate), array_sum(array_map(fn ($p) => $p['emballage']['capacite'] - $p['nombre_unites'], $candidate)),
                                array_map(fn ($p) => [-$p['emballage']['capacite'], $p['emballage']['priorite'], $p['emballage']['id'], json_encode($p['contenu'])], $candidate)];
                            if ($bestScore === null || $score < $bestScore) { $best = $candidate; $bestScore = $score; }
                            return;
                        }
                        $max = min($remaining[$i], $capacity - $units, intdiv($limit - $grams, $content[$i]['poids_unitaire_g']));
                        $min = $i === $seed ? 1 : 0;
                        for ($qty = $max; $qty >= $min; --$qty) { $enumerate($i + 1, [...$take, $qty], $units + $qty, $grams + $qty * $content[$i]['poids_unitaire_g']); }
                    };
                    $enumerate(0, [], 0, 0);
                }
                return $memo[$state] = $best;
            };
            if (($result = $solve($counts)) !== null) { return $result; }
        }
        throw new \DomainException('Aucun emballage compatible avec le poids des produits ne permet cette livraison. Contactez la boutique.');
    }

    public function parcels(array $plan): array
    {
        return array_map(fn ($p) => ['weight' => ['value' => number_format($p['poids_total_g'] / 1000, 3, '.', ''), 'unit' => 'kg'],
            'dimensions' => $p['emballage']['dimensions']], $plan);
    }

    private function contenu(array $lines): array
    {
        if (!$lines) { throw new \DomainException('Votre panier est vide.'); }
        $content = [];
        foreach ($lines as $line) {
            $product = $line['object']; $weight = $product->getShippingWeightGrams(); $qty = $line['qty'];
            if (!$weight || $weight < 1 || !is_int($qty) || $qty < 1) { throw new \DomainException('Le poids d’expédition de chaque produit doit être renseigné avant de proposer la livraison.'); }
            $content[] = ['produit_id' => $product->getId(), 'nom' => $product->getName(), 'quantite' => $qty, 'poids_unitaire_g' => $weight, 'poids_total_g' => $qty * $weight];
        }
        return $content;
    }
}
