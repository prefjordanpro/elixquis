<?php
namespace App\Tests;

use App\Classe\{Mail, MailDeliveryException};
use Mailjet\{Client, Response, Resources};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\{Email, Address};

final class MailjetMailTest extends TestCase
{
    private function mail(Client $client): Mail
    {
        $mail = $this->getMockBuilder(Mail::class)->onlyMethods(['createClient'])->getMock();
        $mail->method('createClient')->willReturn($client);
        return $mail;
    }
    private function email(): Email
    {
        return (new Email())->from(new Address('sender@example.test', 'Elixquis'))->to(new Address('client@example.test', 'Client'))
            ->subject('Sujet')->html('<p>Contenu</p>')->text('Contenu');
    }
    public function testRenderedContentUsesExistingSendApiWithCorrectPayload(): void
    {
        $response = $this->createMock(Response::class); $response->method('success')->willReturn(true);
        $response->method('getData')->willReturn(['Messages' => [['Status' => 'success', 'To' => [['MessageID' => 42]]]]]);
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('post')->willReturnCallback(function ($resource, $options) use ($response) {
            self::assertSame(Resources::$Email, $resource);
            self::assertSame(['Messages' => [[
                'From' => ['Email' => 'sender@example.test', 'Name' => 'Elixquis'],
                'To' => [['Email' => 'client@example.test', 'Name' => 'Client']],
                'Subject' => 'Sujet', 'HTMLPart' => '<p>Contenu</p>', 'TextPart' => 'Contenu',
                'TrackClicks' => 'disabled', 'TrackOpens' => 'disabled',
            ]]], $options['body']);
            return $response;
        });
        self::assertSame(['message_id' => 42], $this->mail($client)->sendEmail($this->email()));
    }
    public static function rejectedResponses(): iterable
    {
        yield 'HTTP refusé' => [false, ['Messages' => [['Status' => 'error']]]];
        yield 'HTTP accepté mais message refusé' => [true, ['Messages' => [['Status' => 'error']]]];
        yield 'réponse incomplète' => [true, []];
    }
    /** @dataProvider rejectedResponses */
    public function testRejectedMessagesAreNotReportedAsSent(bool $success, array $data): void
    {
        $response = $this->createMock(Response::class); $response->method('success')->willReturn($success); $response->method('getData')->willReturn($data);
        $client = $this->createMock(Client::class); $client->method('post')->willReturn($response);
        $this->expectException(MailDeliveryException::class); $this->mail($client)->sendEmail($this->email());
    }
    public function testNetworkExceptionDoesNotExposeCredentialsOrToken(): void
    {
        $client = $this->createMock(Client::class); $client->method('post')->willThrowException(new \RuntimeException('private-key secret-token'));
        try { $this->mail($client)->sendEmail($this->email()); self::fail('Exception attendue'); }
        catch (MailDeliveryException $e) {
            self::assertStringNotContainsString('private-key', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage()); self::assertNull($e->getPrevious());
        }
    }
    public function testLegacyTemplatePayloadRemainsCompatible(): void
    {
        $previous = [$_ENV['MJ_APIKEY_PUBLIC'] ?? null, $_ENV['MJ_APIKEY_PRIVATE'] ?? null];
        $_ENV['MJ_APIKEY_PUBLIC'] = 'mock-public'; $_ENV['MJ_APIKEY_PRIVATE'] = 'mock-private';
        try {
            $response = $this->createMock(Response::class); $response->method('success')->willReturn(true);
            $response->method('getData')->willReturn(['Messages' => [['Status' => 'success']]]);
            $client = $this->createMock(Client::class); $client->method('post')->willReturnCallback(function ($resource, $options) use ($response) {
                $message = $options['body']['Messages'][0];
                self::assertSame(7306899, $message['TemplateID']); self::assertTrue($message['TemplateLanguage']);
                self::assertStringContainsString('Julie', $message['Variables']['content']);
                return $response;
            });
            $this->mail($client)->send('client@example.test', 'Client', 'Bienvenue', 'welcome.html', ['firstname' => 'Julie']);
        } finally {
            foreach (['MJ_APIKEY_PUBLIC', 'MJ_APIKEY_PRIVATE'] as $i => $key) { if ($previous[$i] === null) unset($_ENV[$key]); else $_ENV[$key] = $previous[$i]; }
        }
    }
}
