<?php
declare(strict_types=1);
namespace App\Tests;

use App\Service\SendcloudService;
use App\Shipping\{SendcloudException, ShippingConfigurationException};
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\{MockHttpClient, Response\MockResponse};

final class SendcloudDiagnosticsTest extends TestCase
{
    public function testApiExceptionLogsTypeAndSanitizedMessageOnly(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void { $this->records[] = [$message, $context]; }
        };
        $api = new SendcloudService(new MockHttpClient(new MockResponse('{"error":"private-address private-key"}', ['http_code' => 400])),
            'private-key', 'private-secret', $logger);
        try { $api->shippingOptions([]); self::fail('Une erreur API est attendue.'); }
        catch (SendcloudException $e) { self::assertStringContainsString('Erreur API Sendcloud', $e->getMessage()); }
        $records = json_encode($logger->records);
        self::assertStringContainsString('exception_type', $records);
        self::assertStringContainsString('HTTP 400', $records);
        foreach (['private-address', 'private-key', 'private-secret'] as $value) { self::assertStringNotContainsString($value, $records); }
    }

    public function testMissingCredentialsAreConfigurationErrorWithoutNetworkCall(): void
    {
        $api = new SendcloudService(new MockHttpClient(static function () { self::fail('Aucun appel réseau ne doit être effectué.'); }),
            '', '', new \Psr\Log\NullLogger());
        $this->expectException(ShippingConfigurationException::class);
        $api->shippingOptions([]);
    }
}
