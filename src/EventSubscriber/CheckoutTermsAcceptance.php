<?php
declare(strict_types=1);
namespace App\EventSubscriber;

use App\Entity\{LegalPage, Order};
use App\Service\LegalMarkup;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;

/** Attach consent within the existing order transaction, without touching payments or carriers. */
#[AsDoctrineListener(event: Events::prePersist)]
final class CheckoutTermsAcceptance
{
    public function __construct(private RequestStack $requests, private LegalMarkup $markup) {}
    public function prePersist(LifecycleEventArgs $event): void
    {
        $order = $event->getObject(); $request = $this->requests->getCurrentRequest();
        if (!$order instanceof Order || !$request || !$request->isMethod('POST') || !in_array($request->attributes->get('_route'), ['app_order_summary', 'app_sendcloud_checkout'], true)) return;
        $manager = $event->getObjectManager();
        $page = $manager->find(LegalPage::class, 'cgv');
        if ($page) $manager->refresh($page, LockMode::PESSIMISTIC_READ);
        $version = $page?->getPublishedVersion();
        if (!$version || !$this->markup->isComplete($version->getContent())) throw new \DomainException('La validation de commande est momentanément indisponible.');
        if ($request->request->get('accept_terms') !== '1') throw new \DomainException('Veuillez lire et accepter les Conditions Générales de Vente.');
        if ((string) $version->getId() !== $request->request->get('terms_version')) throw new \DomainException('Les Conditions Générales de Vente ont changé. Rechargez la page et consultez leur nouvelle version.');
        $order->acceptTerms($version);
    }
}
