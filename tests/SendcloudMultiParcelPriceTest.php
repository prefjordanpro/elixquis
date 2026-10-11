<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{Address, Emballage, Product, User};
use App\Repository\EmballageRepository;
use App\Service\{Colisage, SendcloudDelivery, SendcloudMethodPolicy, SendcloudService, ShippingConfiguration};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SendcloudMultiParcelPriceTest extends TestCase
{
    private function option(string $code, array $prices): array
    {
        $carrier = explode(':', $code)[0];
        return ['code' => $code, 'carrier' => ['code' => $carrier, 'name' => $carrier],
            'functionalities' => ['multicollo' => true], 'requirements' => [],
            'quotes' => array_map(fn ($price) => ['price' => ['total' => ['value' => $price, 'currency' => 'EUR']]], $prices)];
    }

    private function offers(int $quantity, array $options, bool $includesTax = false): array
    {
        $repository = $this->createMock(EmballageRepository::class);
        $repository->method('findBy')->willReturn([(new Emballage())->setNom('Carton 1')->setCapacite(1)
            ->setPoidsVideGrammes(300)->setLongueurCm(12)->setLargeurCm(12)->setHauteurCm(38.5)->setActif(true)]);
        $config = new ShippingConfiguration(true, ['name' => 'Test', 'address_line_1' => 'Test',
            'postal_code' => '75001', 'city' => 'Paris', 'country_code' => 'FR'], [], 20, $includesTax);
        $http = new MockHttpClient(function ($method, $url, $request) use ($quantity, $options) {
            self::assertSame('POST', $method);
            self::assertSame('https://panel.sendcloud.sc/api/v3/shipping-options', $url);
            self::assertCount($quantity, json_decode($request['body'], true)['parcels']);
            return new MockResponse(json_encode(['data' => $options]));
        });
        $service = new SendcloudDelivery(new SendcloudService($http, 'test', 'test', new NullLogger()),
            $config, new SendcloudMethodPolicy(), new Colisage($repository, $config));
        return array_values($service->offers((new Address())->setCountry('FR')->setUser((new User())->setEmail('test@example.test')),
            [['object' => (new Product())->setName('Test')->setShippingWeightGrams(1200), 'qty' => $quantity]]));
    }

    public static function totals(): iterable
    {
        yield 'single unchanged' => [1, ['9.97'], false, 1196];
        yield 'two quotes' => [2, ['9.97', '5.04'], false, 1801];
        yield 'three quotes' => [3, ['9.97', '5.04', '2.50'], false, 2101];
        yield 'already includes tax' => [2, ['9.97', '5.04'], true, 1501];
        yield 'round only after sum and tax' => [2, ['0.02', '0.02'], false, 5];
    }

    /** @dataProvider totals */
    public function testCompleteMethodTotal(int $quantity, array $prices, bool $includesTax, int $expected): void
    {
        $offers = $this->offers($quantity, [$this->option('chronopost:18', $prices)], $includesTax);
        self::assertCount(1, $offers);
        self::assertSame($expected, $offers[0]['price_cents']);
    }

    public function testIncompleteMethodsAndCarriersCannotBeCombined(): void
    {
        self::assertSame([], $this->offers(2, [$this->option('chronopost:18', ['9.97']),
            $this->option('chronopost:13', ['5.04']), $this->option('colissimo:home', ['5.04'])]));
    }

    public function testCompleteMethodsAreSummedSeparatelyBeforeShortlisting(): void
    {
        $offers = $this->offers(2, [$this->option('chronopost:18', ['18.56', '13.35']),
            $this->option('chronopost:13', ['10.00', '30.00']), $this->option('colissimo:home', ['19.63', '12.01'])]);
        self::assertCount(2, $offers);
        self::assertSame(['colissimo:home', 'chronopost:18'], array_column($offers, 'code'));
        self::assertSame([3797, 3829], array_column($offers, 'price_cents'));
    }

    public function testInvalidOrExtraQuotesSuppressEntireMethod(): void
    {
        $base = $this->option('chronopost:18', ['9.97', '5.04']);
        $variants = [];
        $option = $base; $option['quotes'][1]['price']['total']['currency'] = 'USD'; $variants[] = $option;
        $option = $base; unset($option['quotes'][1]['price']); $variants[] = $option;
        $option = $base; $option['quotes'][1]['quote_error'] = 'unavailable'; $variants[] = $option;
        $option = $base; $option['quotes'][1]['price']['total']['value'] = '-1.00'; $variants[] = $option;
        $option = $base; $option['quotes'][] = $option['quotes'][0]; $variants[] = $option;
        $option = $base; $option['functionalities']['multicollo'] = false; $variants[] = $option;
        foreach ($variants as $option) { self::assertSame([], $this->offers(2, [$option])); }
    }
}
