<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => 'onResponse']; }
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $response = $event->getResponse();
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if (preg_match('~^/(compte|commande|admin)(?:/|$)~', $event->getRequest()->getPathInfo())) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
    }
}
