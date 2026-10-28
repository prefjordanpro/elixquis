<?php
namespace App\Service;

use App\Entity\Revendeur;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RevendeurGeocoder
{
    public function __construct(private HttpClientInterface $http) {}

    /** Failure leaves the record available for manual correction, never stale coordinates. */
    public function locate(Revendeur $revendeur): bool
    {
        $revendeur->setLatitude(null)->setLongitude(null);
        if (mb_strtolower(trim($revendeur->getPays())) !== 'france') { return false; }
        try {
            $data = $this->http->request('GET', 'https://data.geopf.fr/geocodage/search', [
                'query' => ['q' => trim($revendeur->getAdresse().' '.$revendeur->getCodePostal().' '.$revendeur->getVille()), 'index' => 'address', 'limit' => 1],
                'timeout' => 3, 'max_duration' => 5,
            ])->toArray();
            $feature = $data['features'][0] ?? [];
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            if (($feature['geometry']['type'] ?? '') !== 'Point' || count($coordinates) !== 2
                || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])
                || abs((float) $coordinates[0]) > 180 || abs((float) $coordinates[1]) > 90
                || (isset($feature['properties']['score']) && $feature['properties']['score'] < 0.5)) {
                return false;
            }
            $revendeur->setLongitude((float) $coordinates[0])->setLatitude((float) $coordinates[1]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
