<?php
declare(strict_types=1);
namespace App\DataFixtures;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Check before Doctrine's executor can purge any existing application data. */
final class RequireAppendSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [ConsoleEvents::COMMAND => ['onCommand', 100]]; }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if ($event->getCommand()?->getName() === 'doctrine:fixtures:load' && !$event->getInput()->getOption('append')) {
            throw new \RuntimeException('Ajoutez --append pour conserver les données existantes. Pour retirer la démo, utilisez app:demo:revendeurs:remove.');
        }
    }
}
