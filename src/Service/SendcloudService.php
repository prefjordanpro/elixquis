<?php
declare(strict_types=1);
namespace App\Service;

use App\Shipping\SendcloudException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** API v3 ; aucune relance automatique des opérations d’annonce. */
final class SendcloudService
{
    private const BASE = 'https://panel.sendcloud.sc/api/v3/';
    public function __construct(private HttpClientInterface $http, #[\SensitiveParameter] private string $publicKey,
        #[\SensitiveParameter] private string $secretKey, private LoggerInterface $logger, private bool $allowLabelCreation = false) {}

    public function labelCreationAllowed(): bool { return $this->allowLabelCreation; }
    public function senderAddresses(): array { return $this->json('GET', 'addresses/sender-addresses')['data'] ?? []; }
    public function contracts(): array { return $this->json('GET', 'contracts')['data'] ?? []; }
    public function shippingOptions(array $payload): array { return $this->json('POST', 'shipping-options', ['json' => $payload])['data'] ?? []; }
    public function servicePoints(array $query): array { return $this->json('GET', 'service-points', ['query' => $query])['data']['results'] ?? []; }
    public function servicePoint(int $id): array { return $this->json('GET', 'service-points/'.$id)['data'] ?? []; }
    public function pointAvailable(int $id): bool { return ($this->json('POST', 'service-points/'.$id.'/check-availability')['data']['is_available'] ?? false) === true; }
    public function shipment(string $id): array { return $this->json('GET', 'shipments/'.rawurlencode($id))['data'] ?? []; }
    public function shipmentsForOrder(string $reference): array { return $this->json('GET', 'shipments', ['query' => ['order_number' => $reference]])['data'] ?? []; }
    public function shipmentsForReference(string $reference): array { return $this->json('GET', 'shipments', ['query' => ['external_reference_id' => $reference]])['data'] ?? []; }
    public function createShipment(array $payload): array
    {
        if (!$this->allowLabelCreation) { throw new SendcloudException('La création d’étiquettes est désactivée en attente d’autorisation.'); }
        return $this->json('POST', 'shipments/announce', ['json' => $payload])['data'] ?? [];
    }
    public function label(int $parcelId): string
    {
        try {
            $response = $this->request('GET', 'parcels/'.$parcelId.'/documents/label', ['query' => ['mime_type' => 'application/pdf']]);
            if ($response->getStatusCode() !== 200) { throw new \RuntimeException(); }
            $content = $response->getContent(false);
            if (!str_starts_with($content, '%PDF-') || strlen($content) > 20000000) { throw new \RuntimeException(); }
            return $content;
        } catch (\Throwable) { throw new SendcloudException('L’étiquette Sendcloud est momentanément indisponible.'); }
    }
    private function json(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->request($method, $path, $options); $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Appel Sendcloud refusé.', ['operation' => $path, 'status' => $status]);
                throw new \RuntimeException('HTTP '.$status);
            }
            $data = $response->toArray(false);
            if (isset($data['data']) && !is_array($data['data'])) { throw new \RuntimeException('Structure JSON inattendue.'); }
            if (isset($data['data']['results']) && !is_array($data['data']['results'])) { throw new \RuntimeException('Structure JSON inattendue.'); }
            return $data;
        } catch (\Throwable $e) {
            // Ne jamais journaliser l’exception brute, son contexte HTTP ou la réponse API.
            $message = $e instanceof \App\Shipping\ShippingConfigurationException ? $e->getMessage()
                : (isset($status) && ($status < 200 || $status >= 300) ? 'Réponse API HTTP '.$status
                    : ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
                        ? 'Échec du transport HTTP (détails privés masqués).'
                        : 'Réponse API illisible ou invalide (détails privés masqués).'));
            $this->logger->error('Erreur Sendcloud.', ['operation' => $path, 'exception_type' => $e::class, 'message' => $message]);
            if ($e instanceof \App\Shipping\ShippingConfigurationException) { throw $e; }
            throw new SendcloudException('Erreur API Sendcloud : Sendcloud est indisponible ou n’a pas accepté la demande. Réessayez plus tard.');
        }
    }
    private function request(string $method, string $path, array $options): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        if ($this->publicKey === '' || $this->secretKey === '') { throw new \App\Shipping\ShippingConfigurationException('Configuration incomplète : la connexion Sendcloud doit être configurée.'); }
        return $this->http->request($method, self::BASE.$path, $options + ['auth_basic' => [$this->publicKey, $this->secretKey],
            'headers' => ['Accept' => 'application/json'], 'timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0]);
    }
}
