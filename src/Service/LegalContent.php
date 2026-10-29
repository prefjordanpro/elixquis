<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{LegalPage, LegalVersion, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LegalContent
{
    public function __construct(private EntityManagerInterface $em, private LegalMarkup $markup, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}
    public function initialize(): array
    {
        foreach (LegalPage::LABELS as $type => $label) {
            if (!$this->em->find(LegalPage::class, $type)) {
                $content = $type === 'cgv' ? file_get_contents($this->projectDir.'/migrations/data/cgv-initial-20261028.html') : '';
                $this->em->persist(new LegalPage($type, $content));
            }
        }
        $this->em->flush();
        return array_map(fn ($type) => $this->em->find(LegalPage::class, $type), array_keys(LegalPage::LABELS));
    }
    public function published(string $type): ?LegalVersion
    {
        $version = $this->em->find(LegalPage::class, $type)?->getPublishedVersion();
        return $version && $this->markup->isPublishable($version->getContent(), $type) ? $version : null;
    }
    public function saveDraft(LegalPage $page, string $title, string $content, int $revision): void
    {
        $this->em->lock($page, LockMode::OPTIMISTIC, $revision);
        $title = trim(strip_tags($title));
        if ($title === '' || mb_strlen($title) > 255 || preg_match('/\{[{%#]/', $title)) throw new \DomainException('Indiquez un titre de 1 à 255 caractères, sans code.');
        $page->saveDraft($title, $this->markup->clean($content)); $this->em->flush();
    }
    public function publish(LegalPage $page, int $revision, ?User $publisher): LegalVersion
    {
        $publisher = $publisher?->getId() ? $this->em->find(User::class, $publisher->getId()) : null;
        if (!$this->markup->isPublishable($this->markup->clean($page->getDraftContent()), $page->getId()) || !$this->markup->isComplete($page->getDraftTitle())) throw new \DomainException('À compléter avant publication définitive : renseignez les éléments manquants du document.');
        if (!$page->hasChanges()) throw new \DomainException('Aucune nouvelle modification à publier.');
        return $this->em->wrapInTransaction(function () use ($page, $revision, $publisher) {
            $this->em->refresh($page, LockMode::PESSIMISTIC_WRITE);
            if ($page->getRevision() !== $revision) throw new \DomainException('Ce document a changé. Rechargez la page avant de publier.');
            if (!$this->markup->isPublishable($page->getDraftContent(), $page->getId()) || !$this->markup->isComplete($page->getDraftTitle()) || !$page->hasChanges()) throw new \DomainException('Vérifiez le brouillon avant publication.');
            $date = new \DateTimeImmutable();
            $content = str_replace('[DATE DE PUBLICATION]', $date->format('d/m/Y'), $this->markup->clean($page->getDraftContent()));
            if (!$this->markup->isPublishable($content, $page->getId())) throw new \DomainException('Le document nettoyé ne peut pas être publié en l’état.');
            $version = new LegalVersion($page, ($page->getPublishedVersion()?->getNumber() ?? 0) + 1, $content, $publisher, $date);
            $this->em->persist($version); $page->publish($version);
            return $version;
        });
    }
}
