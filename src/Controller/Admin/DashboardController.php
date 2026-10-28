<?php
namespace App\Controller\Admin;

use App\Entity\{Order, Product, PromoCode};
use App\Service\CommercialPricing;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Assets, Dashboard, MenuItem};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private EntityManagerInterface $em, private CommercialPricing $pricing) {}
    public function index(): Response
    {
        $products = $this->em->getRepository(Product::class);
        $orders = $this->em->getRepository(Order::class);
        $threshold = $this->pricing->settings()->getLowStockThreshold();
        $stock = $products->createQueryBuilder('p')->where('p.stock <= :threshold')
            ->setParameter('threshold', $threshold)->orderBy('p.stock', \SortDirection::Ascending)->addOrderBy('p.name', \SortDirection::Ascending)->setMaxResults(8)->getQuery()->getResult();
        return $this->render('admin/dashboard.html.twig', [
            'prepareCount' => $orders->count(['state' => [1, 2]]),
            'paidCount' => $orders->count(['state' => 1]),
            'shippedCount' => $orders->count(['state' => 3]),
            'outCount' => $products->count(['stock' => 0]),
            'lowCount' => (int) $products->createQueryBuilder('p')->select('COUNT(p.id)')->where('p.stock > 0 AND p.stock <= :threshold')->setParameter('threshold', $threshold)->getQuery()->getSingleScalarResult(),
            'promotionCount' => count(array_filter($products->findBy(['promotionEnabled' => true, 'isActive' => true]), static fn (Product $p) => $p->isPromotionActive())),
            'codeCount' => count(array_filter($this->em->getRepository(PromoCode::class)->findBy(['active' => true]), static fn (PromoCode $p) => $p->isAvailable())),
            'threshold' => $threshold, 'stockAlerts' => $stock,
            'preparing' => $orders->findBy(['state' => [1, 2]], ['createdAt' => 'ASC'], 6),
        ]);
    }
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Elixquis')->setTranslationDomain('messages');
    }
    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('assets/css/palette.css')->addCssFile('assets/css/admin-responsive.css')->addCssFile('assets/css/admin-workspace.css')->addJsFile('assets/js/admin-workspace.js');
    }
    public function configureActions(): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return parent::configureActions()
            ->update('edit', 'saveAndReturn', fn ($action) => $action->setLabel('Enregistrer'))
            ->update('edit', 'saveAndContinue', fn ($action) => $action->setLabel('Enregistrer et continuer'))
            ->update('new', 'saveAndReturn', fn ($action) => $action->setLabel('Créer'))
            ->update('new', 'saveAndAddAnother', fn ($action) => $action->setLabel('Créer puis ajouter un autre'));
    }
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Vue d’ensemble', 'fas fa-house');
        yield MenuItem::section('VENTES');
        yield MenuItem::linkTo(OrderCrudController::class, 'Commandes', 'fas fa-shopping-bag');
        yield MenuItem::linkTo(UserCrudController::class, 'Clients', 'fas fa-users');
        yield MenuItem::section('CATALOGUE');
        yield MenuItem::linkTo(ProductCrudController::class, 'Produits', 'fas fa-wine-bottle');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fas fa-tags');
        yield MenuItem::linkToRoute('Stock', 'fas fa-boxes-stacked', 'admin_stock');
        yield MenuItem::section('PROMOTIONS');
        yield MenuItem::linkToRoute('Promotions produits', 'fas fa-percent', 'admin_promotions');
        yield MenuItem::linkTo(PromoCodeCrudController::class, 'Codes promo', 'fas fa-ticket');
        yield MenuItem::linkToRoute('Livraison offerte', 'fas fa-truck', 'admin_free_shipping');
        yield MenuItem::section('LIVRAISON');
        yield MenuItem::linkTo(EmballageCrudController::class, 'Emballages', 'fas fa-box');
        yield MenuItem::section('SITE');
        yield MenuItem::linkToRoute('Modifier le site', 'fas fa-pen', 'admin_site');
        yield MenuItem::linkToRoute('Pages légales', 'fas fa-file-lines', 'admin_legal');
        yield MenuItem::linkTo(RevendeurCrudController::class, 'Revendeurs', 'fas fa-map-marker-alt');
        yield MenuItem::linkToRoute('Voir la boutique', 'fas fa-arrow-up-right-from-square', 'app_home')->setLinkTarget('_blank');
    }
}
