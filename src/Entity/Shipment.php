<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_SHIPMENT_ORDER', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_SHIPMENT_REMOTE', columns: ['sendcloud_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_SHIPMENT_PARCEL', columns: ['parcel_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_SHIPMENT_REQUEST', columns: ['request_reference'])]
class Shipment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\OneToOne(inversedBy: 'shipment')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Order $order;
    #[ORM\Column(length: 24)]
    private string $state = 'creating';
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $sendcloudId = null;
    #[ORM\Column(nullable: true, unique: true)]
    private ?int $parcelId = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $trackingNumber = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $trackingUrl = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $statusCode = null;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;
    #[ORM\Column]
    private bool $hasLabel = false;
    #[ORM\Column(length: 64, unique: true)]
    private string $requestReference;
    public function __construct(Order $order) { $this->order = $order; $order->setShipment($this); $this->requestedAt = new \DateTimeImmutable(); $this->requestReference = 'elixquis-'.bin2hex(random_bytes(16)); }
    public function getRequestReference(): string { return $this->requestReference; }
    public function isActive(): bool { return $this->statusCode !== 'CANCELLED'; }
    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getState(): string { return $this->state; }
    public function markUnknown(): void { $this->state = 'unknown'; }
    public function getSendcloudId(): ?string { return $this->sendcloudId; }
    public function getParcelId(): ?int { return $this->parcelId; }
    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function getTrackingUrl(): ?string { return $this->trackingUrl; }
    public function getStatusCode(): ?string { return $this->statusCode; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function hasLabel(): bool { return $this->hasLabel; }
    public function getCustomerStatus(): string
    {
        return match ($this->statusCode) {
            'DELIVERED' => 'Colis livré', 'READY_TO_SEND' => 'Expédition préparée', 'IN_TRANSIT', 'ANNOUNCED' => 'Colis en cours d’acheminement',
            'CANCELLED' => 'Expédition annulée', 'DELIVERY_FAILED' => 'Livraison à vérifier',
            default => $this->state === 'ready' ? 'Suivi en cours de mise à jour' : 'Préparation de votre expédition',
        };
    }
    public function synchronize(array $data): void
    {
        $parcel = $data['parcels'][0] ?? [];
        if (!is_string($data['id'] ?? null) || !is_int($parcel['id'] ?? null)
            || ($this->sendcloudId && $this->sendcloudId !== $data['id']) || ($this->parcelId && $this->parcelId !== $parcel['id'])) {
            throw new \DomainException('La réponse d’expédition Sendcloud est incohérente.');
        }
        $this->sendcloudId = $data['id']; $this->parcelId = $parcel['id']; $this->state = 'ready';
        $this->trackingNumber = is_string($parcel['tracking_number'] ?? null) ? $parcel['tracking_number'] : null;
        $url = $parcel['tracking_url'] ?? null;
        $this->trackingUrl = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null;
        $this->statusCode = $parcel['status']['code'] ?? null;
        $this->hasLabel = false;
        foreach ($parcel['documents'] ?? [] as $document) { if (($document['type'] ?? '') === 'label') { $this->hasLabel = true; } }
        $this->updatedAt = new \DateTimeImmutable();
    }
}
