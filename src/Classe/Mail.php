<?php

namespace App\Classe;

use Mailjet\Client;
use Mailjet\Resources;

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
        $mj = new Client($_ENV['MJ_APIKEY_PUBLIC'], $_ENV['MJ_APIKEY_PRIVATE'], true, ['version' => 'v3.1']);
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

        $mj->post(Resources::$Email, ['body' => $body]);
    }
}
