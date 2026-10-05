<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Order, Shipment};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class SendcloudShipping
{
    public function __construct(private EntityManagerInterface $em, private SendcloudService $api, private LoggerInterface $logger) {}
    public function create(Order $order): Shipment
    {
        if (!$this->api->labelCreationAllowed()) { throw new \DomainException('La création d’étiquettes reste désactivée en attente d’autorisation.'); }
        $snapshot = $order->getShippingSnapshot();
        if (($snapshot['provider'] ?? '') !== 'sendcloud') { throw new \DomainException('Cette commande utilise l’ancien système de livraison.'); }
        $existing = false;
        $shipment = $this->em->wrapInTransaction(function () use ($order, &$existing): Shipment {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $record = $this->em->getRepository(Shipment::class)->findOneBy(['order' => $order]);
            if ($record) { $existing = true; return $record; }
            if (!in_array($order->getState(), [1, 2], true) || !$order->getStripePaymentIntentId() || $order->getStripeRefundId()) {
                throw new \DomainException('Seule une commande payée, sans annulation ni remboursement en cours, peut être expédiée.');
            }
            $record = new Shipment($order); $this->em->persist($record); $this->em->flush();
            return $record;
        });
        if ($existing) { return $shipment; }
        $payload = ['from_address' => $snapshot['from_address'], 'to_address' => $snapshot['to_address'], 'parcels' => $snapshot['parcels'],
            'ship_with' => $snapshot['ship_with'], 'order_number' => $order->getReference(), 'external_reference_id' => $shipment->getRequestReference(),
            'total_order_price' => ['value' => number_format($order->getTotalWt(), 2, '.', ''), 'currency' => 'EUR'],
            'label_details' => ['mime_type' => 'application/pdf', 'dpi' => 72]];
        if ($snapshot['service_point']) { $payload['to_service_point'] = ['id' => $snapshot['service_point']['id']]; }
        foreach ($snapshot['colisage'] ?? [] as $index => $colis) {
            $payload['parcels'][$index]['parcel_items'] = array_map(function ($item) use ($snapshot) {
                $facts = array_values(array_filter($snapshot['items'], fn ($facts) => $facts['id'] === $item['produit_id']))[0];
                return ['item_id' => (string) $item['produit_id'], 'description' => $item['nom'], 'quantity' => $item['quantite'],
                    'weight' => ['value' => number_format($item['poids_unitaire_g'] / 1000, 3, '.', ''), 'unit' => 'kg'],
                    'price' => ['value' => number_format($facts['price_cents'] / 100, 2, '.', ''), 'currency' => 'EUR']];
            }, $colis['contenu']);
        }
        foreach ($snapshot['colisage'] ?? [] as $index => $colis) {
            $payload['parcels'][$index]['parcel_items'] = array_map(function ($item) use ($snapshot) {
                $facts = array_values(array_filter($snapshot['items'], fn ($facts) => $facts['id'] === $item['produit_id']))[0];
                return ['item_id' => (string) $item['produit_id'], 'description' => $item['nom'], 'quantity' => $item['quantite'],
                    'weight' => ['value' => number_format($item['poids_unitaire_g'] / 1000, 3, '.', ''), 'unit' => 'kg'],
                    'price' => ['value' => number_format($facts['price_cents'] / 100, 2, '.', ''), 'currency' => 'EUR']];
            }, $colis['contenu']);
        }
        try {
            $data = $this->api->createShipment($payload);
            $this->verify($shipment, $data); $shipment->synchronize($data); $this->em->flush();
            $this->logger->info('Expédition Sendcloud créée.', ['order' => $order->getId(), 'shipment' => $shipment->getSendcloudId()]);
            return $shipment;
        } catch (\Throwable) {
            if ($this->em->isOpen()) { $shipment->markUnknown(); $this->em->flush(); }
            $this->logger->warning('Résultat d’expédition Sendcloud à vérifier.', ['order' => $order->getId()]);
            throw new \DomainException('Sendcloud n’a pas confirmé l’expédition. Ne recréez pas l’envoi : utilisez « Vérifier l’expédition » pour éviter un doublon.');
        }
    }

    public function synchronize(Shipment $shipment): void
    {
        if ($shipment->getSendcloudId()) { $data = $this->api->shipment($shipment->getSendcloudId()); }
        else {
            $matches = array_values(array_filter($this->api->shipmentsForReference($shipment->getRequestReference()),
                static fn ($item) => ($item['external_reference_id'] ?? null) === $shipment->getRequestReference()));
            if (count($matches) !== 1) { throw new \DomainException('L’expédition n’est pas confirmée. Vérifiez le compte Sendcloud avant toute nouvelle création.'); }
            $data = $matches[0];
        }
        $this->verify($shipment, $data);
        $this->em->wrapInTransaction(function () use ($shipment, $data): void {
            $this->em->refresh($shipment, LockMode::PESSIMISTIC_WRITE); $shipment->synchronize($data);
        });
    }

    private function verify(Shipment $shipment, array $data): void
    {
        if (($data['external_reference_id'] ?? '') !== $shipment->getRequestReference()
            || ($data['order_number'] ?? '') !== $shipment->getOrder()->getReference()
            || ($data['ship_with']['properties']['shipping_option_code'] ?? '') !== $shipment->getOrder()->getShippingSnapshot()['method_code']
            || count($data['parcels'] ?? []) !== count($shipment->getOrder()->getShippingSnapshot()['parcels'] ?? [])) { throw new \DomainException('L’expédition reçue ne correspond pas à cette commande.'); }
        $seen = [];
        foreach ($data['parcels'] as $index => $parcel) {
            if (!is_int($parcel['id'] ?? null) || $parcel['id'] < 1 || isset($seen[$parcel['id']])) {
                throw new \DomainException('Les identifiants des colis Sendcloud sont invalides.');
            }
            $seen[$parcel['id']] = true;
            $saved = $shipment->getOrder()->getColis()->get($index);
            if ($saved?->getSendcloudParcelId() !== null && $saved->getSendcloudParcelId() !== $parcel['id']) {
                throw new \DomainException('Les colis Sendcloud ont changé. Vérification requise.');
            }
            if (count($data['parcels']) > 1 && (($parcel['weight']['unit'] ?? '') !== 'kg'
                || abs((float) ($parcel['weight']['value'] ?? 0) - (float) $shipment->getOrder()->getShippingSnapshot()['parcels'][$index]['weight']['value']) > 0.00001)) {
                throw new \DomainException('Les poids des colis Sendcloud ne correspondent pas au colisage figé.');
            }
        }
    }
}
