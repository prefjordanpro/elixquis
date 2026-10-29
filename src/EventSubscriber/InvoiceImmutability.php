<?php
declare(strict_types=1);
namespace App\EventSubscriber;
use App\Entity\{Invoice, InvoiceLine};
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
final class InvoiceImmutability
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $work = $event->getObjectManager()->getUnitOfWork();
        foreach ($work->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof InvoiceLine && !$work->isScheduledForInsert($entity->getInvoice())) throw new \DomainException('Les lignes d’un document émis sont conservées en lecture seule.');
        }
        foreach ($work->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof InvoiceLine && !$work->isScheduledForInsert($entity->getInvoice())) throw new \DomainException('Les lignes d’un document émis sont conservées en lecture seule.');
        }
        foreach (array_merge($work->getScheduledEntityUpdates(), $work->getScheduledEntityDeletions()) as $entity) {
            if ($entity instanceof Invoice || $entity instanceof InvoiceLine) throw new \DomainException('Un document émis est conservé en lecture seule.');
        }
    }
}
