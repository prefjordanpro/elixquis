<?php
declare(strict_types=1);
namespace App\Tests\Support;

use App\Entity\{LegalPage, LegalVersion};
use App\Service\LegalContent;
use Doctrine\ORM\EntityManagerInterface;

/** Explicit contract fixture for checkout tests only; never registered in the application. */
trait LegalTerms
{
    private function publishTestTerms(EntityManagerInterface $em): LegalVersion
    {
        $page = $em->find(LegalPage::class, 'cgv');
        if ($page?->getPublishedVersion()) return $page->getPublishedVersion();
        if (!$page) { $page = new LegalPage('cgv', '<h2>Document réservé aux tests</h2><p>Contenu contractuel fictif pour les tests automatiques.</p>'); $em->persist($page); $em->flush(); }
        return static::getContainer()->get(LegalContent::class)->publish($page, $page->getRevision(), null);
    }
}
