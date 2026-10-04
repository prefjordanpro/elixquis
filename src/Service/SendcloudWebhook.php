<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\Shipment;
use Doctrine\ORM\EntityManagerInterface;

final class SendcloudWebhook
{
    public function __construct(public readonly bool $enabled, #[\SensitiveParameter] private string $signatureKey,
        private EntityManagerInterface $em, private SendcloudShipping $shipping) {}
    public function authentic(string $body, string $signature): bool
    {
        return $this->signatureKey !== '' && preg_match('/^[a-f0-9]{64}$/D', $signature)
            && hash_equals(hash_hmac('sha256', $body, $this->signatureKey), $signature);
    }
    public function handle(array $event): void
    {
        if (($event['action'] ?? '') !== 'parcel_status_changed') { return; }
        $id = $event['parcel']['id'] ?? null;
        if (!is_int($id) || $id < 1) { throw new \DomainException('Événement Sendcloud invalide.'); }
        $shipment = $this->em->getRepository(Shipment::class)->findOneBy(['parcelId' => $id]);
        // Un événement tardif ou répété ne remplace pas le statut par une ancienne valeur.
        if ($shipment) { $this->shipping->synchronize($shipment); }
    }
}
