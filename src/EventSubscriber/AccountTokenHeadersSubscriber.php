<?php
namespace App\EventSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AccountTokenHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => ['headers', -20]]; }
    public function headers(ResponseEvent $event): void
    {
        if (in_array($event->getRequest()->attributes->get('_route'), ['app_password_reset', 'app_email_verify'], true)) {
            $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
        }
    }
}
