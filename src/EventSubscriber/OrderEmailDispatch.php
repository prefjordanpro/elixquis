<?php
namespace App\EventSubscriber;

use App\Service\OrderEmails;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OrderEmailDispatch implements EventSubscriberInterface
{
    public function __construct(private OrderEmails $emails, private LoggerInterface $logger,
        #[Autowire('%kernel.environment%')] private string $environment) {}
    public static function getSubscribedEvents(): array
    {
        return ['kernel.terminate' => 'dispatch', 'console.terminate' => 'dispatch'];
    }
    public function dispatch(): void
    {
        // Tests explicitly invoke the dispatcher with a mocked Mail transport.
        if ($this->environment === 'test') { return; }
        try { $this->emails->dispatch(); }
        catch (\Throwable $e) { $this->logger->warning('Traitement des courriels de commande indisponible.'); }
    }
}
