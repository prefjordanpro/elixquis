<?php
namespace App\Controller\Admin;

use App\Entity\{CommercialSettings, Product};
use App\Service\CommercialPricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class WorkspaceController extends AbstractController
{
    #[AdminRoute(path: '/modifier-le-site', name: 'site', options: ['methods' => ['GET']])]
    public function site(): Response { return $this->render('admin/site.html.twig'); }

    #[AdminRoute(path: '/stock', name: 'stock', options: ['methods' => ['GET']])]
    #[AdminRoute(path: '/promotions-produits', name: 'promotions', options: ['methods' => ['GET']])]
    public function products(Request $request, EntityManagerInterface $em, CommercialPricing $pricing): Response
    {
        $promotion = $request->attributes->get('_route') === 'admin_promotions';
        $threshold = $pricing->settings()->getLowStockThreshold();
        $query = $em->getRepository(Product::class)->createQueryBuilder('p');
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        if ($search !== '') { $query->andWhere('LOWER(p.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%'); }
        $level = $request->query->getString('niveau');
        if (!$promotion) {
            if ($level === 'rupture') $query->andWhere('p.stock = 0');
            elseif ($level === 'faible') $query->andWhere('p.stock > 0 AND p.stock <= :threshold')->setParameter('threshold', $threshold);
            elseif ($level === 'normal') $query->andWhere('p.stock > :threshold')->setParameter('threshold', $threshold);
        }
        if ($promotion && $request->query->getString('selection') === 'configurees') $query->andWhere('p.promotionEnabled = true');
        $total = (int) (clone $query)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 30)); $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $query->orderBy($promotion ? 'p.name' : 'p.stock', \SortDirection::Ascending)->addOrderBy('p.id', \SortDirection::Ascending)->setMaxResults(30)->setFirstResult(($page - 1) * 30);
        return $this->render('admin/product_workspace.html.twig', [
            'promotion' => $promotion, 'products' => $query->getQuery()->getResult(), 'threshold' => $threshold,
            'total' => $total, 'page' => $page, 'pages' => $pages, 'search' => $search, 'level' => $level,
        ]);
    }
    #[AdminRoute(path: '/regler-le-stock', name: 'stock_settings', options: ['methods' => ['GET']])]
    #[AdminRoute(path: '/livraison-offerte', name: 'free_shipping', options: ['methods' => ['GET']])]
    public function settings(Request $request, EntityManagerInterface $em): Response
    {
        if (!$em->find(CommercialSettings::class, 1)) { $em->persist(new CommercialSettings()); $em->flush(); }
        return $this->redirectToRoute('admin_commercial_settings_edit', ['entityId' => 1, 'section' => $request->attributes->get('_route') === 'admin_stock_settings' ? 'stock' : 'livraison']);
    }
}
