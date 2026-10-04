<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\SendcloudWebhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class SendcloudWebhookController extends AbstractController
{
    #[Route('/webhooks/sendcloud', name: 'app_sendcloud_webhook', methods: ['POST'])]
    public function receive(Request $request, SendcloudWebhook $webhook): Response
    {
        if (!$webhook->enabled) { throw $this->createNotFoundException(); }
        $body = $request->getContent();
        if (strlen($body) > 100000) { return new Response('', 413); }
        if (!$webhook->authentic($body, $request->headers->get('Sendcloud-Signature', ''))) { return new Response('', 401); }
        try { $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return new Response('', 400); }
        if (!is_array($event)) { return new Response('', 400); }
        try { $webhook->handle($event); }
        catch (\DomainException) { return new Response('', 503); }
        return new Response('', 204);
    }
}
