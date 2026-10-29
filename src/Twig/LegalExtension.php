<?php
declare(strict_types=1);
namespace App\Twig;

use App\Entity\{LegalPage, LegalVersion};
use App\Repository\CategoryRepository;
use App\Service\{LegalContent, LegalMarkup};
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LegalExtension extends AbstractExtension
{
    public function __construct(private LegalContent $content, private LegalMarkup $markup, private EntityManagerInterface $em, private CategoryRepository $categories) {}
    public function getFunctions(): array
    {
        return [new TwigFunction('legal_links', $this->links(...)), new TwigFunction('legal_terms', fn () => $this->content->published('cgv')),
            new TwigFunction('legal_complete', fn ($html) => $this->markup->isComplete($html)),
            new TwigFunction('legal_publishable', fn ($html, $type) => $this->markup->isPublishable($html, $type)),
            new TwigFunction('footer_categories', fn () => $this->categories->createQueryBuilder('c')->select('DISTINCT c')->innerJoin('c.products', 'p')->where('p.isActive = true')->orderBy('c.id', \SortDirection::Ascending)->getQuery()->getResult())];
    }
    public function links(): array
    {
        $links = [];
        $pages = $this->em->getRepository(LegalPage::class)->createQueryBuilder('p')->addSelect('v')->leftJoin('p.publishedVersion', 'v')->getQuery()->getResult();
        $byType = []; foreach ($pages as $page) $byType[$page->getId()] = $page;
        foreach (LegalPage::LABELS as $type => $label) {
            $version = ($byType[$type] ?? null)?->getPublishedVersion();
            if ($version && $this->markup->isPublishable($version->getContent(), $type)) $links[] = ['route' => 'app_legal_'.$type, 'label' => $type === 'cookies' ? 'Gestion des cookies' : $label];
        }
        if (isset($byType['cgv']) && $this->content->published('cgv')) $links[] = ['route' => 'app_legal_withdrawal', 'label' => 'Exercer mon droit de rétractation'];
        return $links;
    }
}
