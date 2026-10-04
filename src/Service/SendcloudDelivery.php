<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Address;
use App\Shipping\DeliverySelection;

final class SendcloudDelivery
{
    public function __construct(private SendcloudService $api, private ShippingConfiguration $configuration,
        private SendcloudMethodPolicy $policy) {}

    public function offers(Address $address, array $lines, ?int $pointId = null): array
    {
        $sender = $this->configuration->sender();
        // Une première intégration nationale : pas de formalités douanières inventées.
        if ($address->getCountry() !== $sender['country_code']) { throw new \DomainException('Cette adresse nécessite une livraison internationale non encore proposée.'); }
        $parcel = $this->configuration->parcel($lines);
        $this->configuration->sellingPrice('0.00');
        $addressFields = array_flip(['country_code', 'postal_code', 'city', 'address_line_1', 'address_line_2', 'house_number', 'state_province_code']);
        $payload = ['from_address' => array_intersect_key($sender, $addressFields),
            'to_address' => array_intersect_key($this->destination($address), $addressFields), 'parcels' => [$parcel], 'calculate_quotes' => true];
        if ($pointId !== null) { $payload['to_service_point'] = ['id' => $pointId]; }
        $offers = [];
        foreach ($this->api->shippingOptions($payload) as $option) {
            $category = $this->policy->category($option);
            if ($category === null) { continue; }
            if (($option['quote_error'] ?? null) !== null || !empty($option['requirements']['export_documents'])
                || !empty($option['requirements']['fields']) || !is_string($option['code'] ?? null)
                || !is_string($option['carrier']['code'] ?? null) || !is_string($option['carrier']['name'] ?? null)) { continue; }
            $quote = null;
            foreach ($option['quotes'] ?? [] as $candidate) {
                $min = $candidate['weight']['min'] ?? null; $max = $candidate['weight']['max'] ?? null;
                $weight = (float) $parcel['weight']['value'];
                if (($candidate['price']['total']['currency'] ?? '') !== 'EUR'
                    || ($min && (($min['unit'] ?? '') !== 'kg' || $weight < (float) $min['value']))
                    || ($max && (($max['unit'] ?? '') !== 'kg' || $weight >= (float) $max['value']))) { continue; }
                $quote = $candidate; break;
            }
            if (!$quote) { continue; }
            $pointRequired = ($option['requirements']['is_service_point_required'] ?? false) === true;
            $contractId = $option['contract']['id'] ?? null;
            if ($contractId !== null && !is_int($contractId)) { continue; }
            $key = hash('sha256', $option['code'].'|'.($contractId ?? ''));
            $offers[$key] = ['key' => $key, 'code' => $option['code'], 'contract_id' => $contractId,
                'carrier_code' => $option['carrier']['code'], 'carrier_name' => $option['carrier']['name'],
                'method_name' => $option['name'] ?? $option['product']['name'] ?? $option['carrier']['name'],
                'price_cents' => $this->configuration->sellingPrice((string) $quote['price']['total']['value']),
                'api_amount' => $quote['price']['total']['value'], 'lead_time_hours' => $quote['lead_time'] ?? null,
                'category' => $category, 'point_required' => $pointRequired, 'kind' => match ($category) {
                    'relay' => 'Livraison en point relais', 'standard' => 'Livraison standard à domicile', 'express' => 'Livraison express'}];
        }
        return $this->policy->shortlist($offers);
    }

    public function points(Address $address, array $offer): array
    {
        if (!$offer['point_required']) { return []; }
        $points = $this->api->servicePoints(['country_code' => $address->getCountry(), 'carrier_code' => $offer['carrier_code'],
            'address' => $address->getPostal().' '.$address->getCity()]);
        return array_values(array_filter($points, static fn ($point) => ($point['carrier']['code'] ?? '') === $offer['carrier_code']
            && ($point['address']['country_code'] ?? '') === $address->getCountry() && empty($point['is_expired'])));
    }

    public function select(Address $address, array $lines, string $key, ?int $pointId = null): DeliverySelection
    {
        $offers = $this->offers($address, $lines); $offer = $offers[$key] ?? null;
        if (!$offer) { throw new \DomainException('Cette méthode de livraison n’est plus disponible pour votre commande.'); }
        $point = null;
        if ($offer['point_required']) {
            if (!$pointId || $pointId < 1) { throw new \DomainException('Choisissez un point relais.'); }
            $point = $this->api->servicePoint($pointId);
            if (($point['id'] ?? null) !== $pointId || ($point['carrier']['code'] ?? '') !== $offer['carrier_code']
                || ($point['address']['country_code'] ?? '') !== $address->getCountry() || !empty($point['is_expired'])
                || !$this->api->pointAvailable($pointId)) { throw new \DomainException('Le point relais choisi n’est pas valide ou disponible.'); }
            $offer = $this->offers($address, $lines, $pointId)[$key] ?? null;
            if (!$offer) { throw new \DomainException('Cette méthode n’est pas compatible avec le point relais choisi.'); }
            if (!is_string($point['name'] ?? null) || empty($point['address']['postal_code']) || empty($point['address']['city']) || empty($point['address']['street'])) {
                throw new \DomainException('L’adresse du point relais est incomplète.');
            }
            $point = array_intersect_key($point, array_flip(['id', 'name', 'address', 'carrier', 'carrier_service_point_id']));
        } elseif ($pointId !== null) { throw new \DomainException('Cette méthode ne permet pas de livraison en point relais.'); }
        $properties = ['shipping_option_code' => $offer['code']];
        if ($offer['contract_id'] !== null) { $properties['contract_id'] = $offer['contract_id']; }
        return new DeliverySelection(['provider' => 'sendcloud', 'carrier_name' => $offer['carrier_name'], 'carrier_code' => $offer['carrier_code'],
            'method_name' => $offer['method_name'], 'kind' => $offer['kind'], 'method_code' => $offer['code'], 'price_cents' => $offer['price_cents'],
            'tax_rate' => $this->configuration->taxRate, 'quoted_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'service_point' => $point, 'from_address' => $this->configuration->sender(), 'to_address' => $this->destination($address),
            'items' => array_map(static fn ($line) => ['id' => $line['object']->getId(), 'qty' => $line['qty'],
                'weight_grams' => $line['object']->getShippingWeightGrams(), 'price_cents' => (int) round($line['object']->getPriceWt() * 100)], array_values($lines)),
            'parcels' => [$this->configuration->parcel($lines)], 'ship_with' => ['type' => 'shipping_option_code', 'properties' => $properties]],
            $offer['price_cents'], $this->configuration->taxRate);
    }

    private function destination(Address $address): array
    {
        return ['name' => trim($address->getFirstname().' '.$address->getLastname()), 'address_line_1' => $address->getAddress(),
            'postal_code' => $address->getPostal(), 'city' => $address->getCity(), 'country_code' => $address->getCountry(),
            'phone_number' => $address->getPhone(), 'email' => $address->getUser()->getEmail()];
    }
}
