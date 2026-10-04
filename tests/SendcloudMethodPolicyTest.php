<?php
declare(strict_types=1);
namespace App\Tests;
use App\Service\SendcloudMethodPolicy;
use PHPUnit\Framework\TestCase;

final class SendcloudMethodPolicyTest extends TestCase
{
    private function option(array $features = [], string $carrier = 'colissimo', bool $relay = false): array
    {
        return ['code' => $carrier.':parcel', 'name' => 'Service commercial', 'carrier' => ['code' => $carrier],
            'functionalities' => $features, 'requirements' => ['is_service_point_required' => $relay]];
    }
    public function testLetterAndMailboxAreRejectedRegardlessOfName(): void
    {
        $policy = new SendcloudMethodPolicy();
        foreach (['letter', 'mailbox', 'pallet', 'long'] as $format) {
            self::assertNull($policy->category($this->option(['form_factor' => $format])));
        }
        $option = $this->option(); $option['name'] = 'Unstamped Letter';
        self::assertNull($policy->category($option));
        self::assertNull($policy->category($this->option(['returns' => true])));
        self::assertNull($policy->category($this->option(['last_mile' => 'mailbox'])));
    }
    public function testCategoriesUseCarrierAndApiCharacteristics(): void
    {
        $policy = new SendcloudMethodPolicy();
        self::assertSame('standard', $policy->category($this->option(['form_factor' => 'parcel', 'last_mile' => 'home_delivery'])));
        self::assertSame('express', $policy->category($this->option(['premium' => true], 'chronopost')));
        self::assertSame('relay', $policy->category($this->option(['last_mile' => 'service_point'], 'mondial_relay', true)));
        self::assertNull($policy->category($this->option([], 'unknown')));
        self::assertNull($policy->category($this->option(['last_mile' => 'service_point'])));
        self::assertNull($policy->category($this->option(['premium' => true])));
    }
    public function testAllowlistUsesCodeAndCannotOverrideLetterProtection(): void
    {
        $policy = new SendcloudMethodPolicy(['standard' => ['carriers' => ['colissimo'], 'methods' => ['colissimo:approved']]]);
        $option = $this->option(); self::assertNull($policy->category($option));
        $option['code'] = 'colissimo:approved'; self::assertSame('standard', $policy->category($option));
        $option['functionalities']['form_factor'] = 'letter'; self::assertNull($policy->category($option));
    }
    public function testCheapestPerCategoryAndPerRelayCarrierWinsWithConfigurablePreference(): void
    {
        $offers = [];
        foreach ([['a', 'standard', 'colissimo', 800], ['b', 'standard', 'colissimo', 500],
            ['c', 'relay', 'mondial_relay', 400], ['d', 'relay', 'mondial_relay', 600],
            ['e', 'relay', 'colissimo', 450]] as [$key, $category, $carrier, $price]) {
            $offers[$key] = ['key' => $key, 'code' => $key, 'category' => $category, 'carrier_code' => $carrier, 'price_cents' => $price];
        }
        self::assertSame(['c', 'e', 'b'], array_keys((new SendcloudMethodPolicy())->shortlist($offers)));
        $policy = new SendcloudMethodPolicy(['standard' => ['preferred_methods' => ['a']]]);
        self::assertSame(['c', 'e', 'a'], array_keys($policy->shortlist($offers)));
    }
}
