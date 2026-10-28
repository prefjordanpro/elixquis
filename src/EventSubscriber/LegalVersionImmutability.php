<?php
declare(strict_types=1);
namespace App\EventSubscriber;

use App\Entity\LegalVersion;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;

#[AsDoctrineListener(event: Events::onFlush)]
final class LegalVersionImmutability
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $work = $event->getObjectManager()->getUnitOfWork();
        foreach (array_merge($work->getScheduledEntityUpdates(), $work->getScheduledEntityDeletions()) as $entity) {
            if ($entity instanceof LegalVersion) throw new \DomainException('Une version publiée est conservée en lecture seule. Créez un nouveau brouillon.');
        }
    }
}
