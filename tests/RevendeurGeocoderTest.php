<?php
namespace App\Tests;
use App\Entity\{HomePageContent, Revendeur};
use App\Service\RevendeurGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Validation;

final class RevendeurGeocoderTest extends TestCase
{
    public function testIgnRequestAndGeoJsonAxisOrder(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://data.geopf.fr/geocodage/search?', $url);
            self::assertStringContainsString('index=address', $url);
            self::assertSame(3.0, $options['timeout']); self::assertSame(5.0, $options['max_duration']);
            return new MockResponse('{"features":[{"geometry":{"type":"Point","coordinates":[2.34,48.85]},"properties":{"score":0.9}}]}');
        });
        $retailer = (new Revendeur())->setAdresse('1 rue de la Paix')->setCodePostal('75002')->setVille('Paris');
        self::assertTrue((new RevendeurGeocoder($http))->locate($retailer));
        self::assertSame(48.85, $retailer->getLatitude()); self::assertSame(2.34, $retailer->getLongitude());
    }
    /** @dataProvider failures */
    public function testUnavailableOrUnmatchedServiceDoesNotCrash(string $body, array $info): void
    {
        $http = new MockHttpClient(new MockResponse($body, $info));
        $retailer = (new Revendeur())->setLatitude(45)->setLongitude(3);
        self::assertFalse((new RevendeurGeocoder($http))->locate($retailer));
        self::assertNull($retailer->getLatitude()); self::assertNull($retailer->getLongitude());
    }
    public function failures(): array
    {
        return [['{"features":[]}', []], ['Unavailable', ['http_code'=>503]], ['invalid JSON', []], ['{"features":[{"geometry":{"type":"Point","coordinates":[200,100]}}]}', []], ['{"features":[{"geometry":{"type":"Point","coordinates":[2,48]},"properties":{"score":0.1}}]}', []]];
    }
    public function testTransportFailureIsContained(): void
    {
        $http = new MockHttpClient(static function () { throw new \Symfony\Component\HttpClient\Exception\TransportException('Timeout'); });
        self::assertFalse((new RevendeurGeocoder($http))->locate(new Revendeur()));
    }
    public function testCoordinatePairAndUrlsAreValidated(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $retailer = (new Revendeur())->setNom('Test')->setAdresse('Adresse')->setCodePostal('75000')->setVille('Paris')->setLatitude(48);
        self::assertGreaterThan(0, count($validator->validate($retailer)));
        $retailer->setLongitude(2)->setSiteWeb('javascript:alert(1)');
        self::assertGreaterThan(0, count($validator->validate($retailer)));
        foreach (['javascript:alert(1)', '//example.test', '/\\example.test', "https://example.test\n"] as $url) {
            self::assertGreaterThan(0, count($validator->validate((new HomePageContent())->setHeroUrl($url))), $url);
        }
        self::assertCount(0, $validator->validate((new HomePageContent())->setHeroUrl('/catalogue#saveurs')));
    }
}
