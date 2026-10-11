<?php

namespace App\Classe;

use Mailjet\Client;
use Mailjet\Resources;
use Symfony\Component\Mime\Email;

class Mail
{
    public function send($to_email, $to_name, $subject, $template, $vars = null)
    {
        // Récupération du template
        $content = file_get_contents(dirname(__DIR__).'/Mail/'.$template);

        // Récupère les variables facultatives
        if ($vars) {
            foreach($vars as $key=>$var) {
                $content = str_replace('{'.$key.'}', htmlspecialchars((string) $var, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);
            }
        }

        if (!($_ENV['MJ_APIKEY_PUBLIC'] ?? '') || !($_ENV['MJ_APIKEY_PRIVATE'] ?? '')) { return false; }
        $body = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => "swiftfacts9@gmail.com",
                        'Name' => "Elixquis"
                    ],
                    'To' => [
                        [
                            'Email' => $to_email,
                            'Name' => $to_name
                        ]
                    ],
                    'TemplateID' => 7306899,
                    'TemplateLanguage' => true,
                    'Subject' => $subject,
                    'Variables' => [
                        'content' => $content
                    ]
                ]
            ]
        ];

        return $this->deliver($body);
    }

    /** Already rendered Twig content, using the same Mailjet credentials and API as legacy mail. */
    public function sendEmail(Email $email): array
    {
        if (count($email->getTo()) !== 1 || count($email->getFrom()) !== 1 || $email->getCc() || $email->getBcc()) {
            throw new MailDeliveryException('Destinataire ou expéditeur transactionnel invalide.');
        }
        $from = $email->getFrom()[0]; $to = $email->getTo()[0];
        $message = [
            'From' => ['Email' => $from->getAddress(), 'Name' => $from->getName()],
            'To' => [['Email' => $to->getAddress(), 'Name' => $to->getName()]],
            'Subject' => $email->getSubject(),
            'HTMLPart' => $email->getHtmlBody(),
            'TextPart' => $email->getTextBody(),
            // Authentication URLs must remain the configured canonical URLs, without tracking redirects.
            'TrackClicks' => 'disabled',
            'TrackOpens' => 'disabled',
        ];
        if ($email->getReplyTo()) {
            $reply = $email->getReplyTo()[0];
            $message['ReplyTo'] = ['Email' => $reply->getAddress(), 'Name' => $reply->getName()];
        }
        return $this->deliver(['Messages' => [$message]]);
    }

    protected function createClient(): Client
    {
        $public = $_ENV['MJ_APIKEY_PUBLIC'] ?? $_SERVER['MJ_APIKEY_PUBLIC'] ?? '';
        $private = $_ENV['MJ_APIKEY_PRIVATE'] ?? $_SERVER['MJ_APIKEY_PRIVATE'] ?? '';
        if (!$public || !$private) { throw new MailDeliveryException('Mailjet n’est pas configuré.'); }
        return new Client($public, $private, true, ['version' => 'v3.1']);
    }

    private function deliver(array $body): array
    {
        try { $response = $this->createClient()->post(Resources::$Email, ['body' => $body]); }
        catch (\Throwable $e) {
            // Never propagate request bodies, authentication headers or provider errors containing URLs.
            throw new MailDeliveryException('La transmission à Mailjet a échoué.');
        }
        $messages = $response->getData()['Messages'] ?? [];
        if (!$response->success() || count($messages) !== count($body['Messages'])
            || array_filter($messages, fn ($message) => ($message['Status'] ?? null) !== 'success')) {
            throw new MailDeliveryException('Mailjet n’a pas accepté le courriel.');
        }
        return ['message_id' => $messages[0]['To'][0]['MessageID'] ?? null];
    }
}
