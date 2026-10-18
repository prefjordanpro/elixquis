<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private \Doctrine\ORM\EntityManagerInterface $em, private \App\Service\CommercialPricing $pricing) {}
    public function index(): Response
    {
        $products = $this->em->getRepository(\App\Entity\Product::class);
        $orders = $this->em->getRepository(\App\Entity\Order::class);
        $threshold = $this->pricing->settings()->getLowStockThreshold();
        $out = $products->createQueryBuilder('p')->where('p.stock <= 0')->getQuery()->getResult();
        $low = $products->createQueryBuilder('p')->where('p.stock > 0 AND p.stock <= :threshold')->setParameter('threshold', $threshold)->getQuery()->getResult();
        $url = $this->container->get(AdminUrlGenerator::class);
        $stockLinks = [];
        foreach (array_merge($out, $low) as $product) { $stockLinks[$product->getId()] = (clone $url)->unsetAll()->setController(ProductCrudController::class)->setAction('edit')->setEntityId($product->getId())->generateUrl(); }
        $promotions = array_filter($products->findBy(['promotionEnabled' => true]), static fn ($p) => $p->isPromotionActive());
        $codes = array_filter($this->em->getRepository(\App\Entity\PromoCode::class)->findBy(['active' => true]), static fn ($p) => $p->isAvailable());
        return $this->render('admin/dashboard.html.twig', ['ruptures' => $out, 'lowStock' => $low, 'threshold' => $threshold,
            'stockLinks' => $stockLinks, 'pending' => $orders->count(['state' => 0]), 'promotionCount' => count($promotions), 'codeCount' => count($codes),
            'recent' => $orders->findBy([], ['createdAt' => 'DESC'], 8)]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Elixquis');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('assets/css/palette.css')->addCssFile('assets/css/admin-responsive.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::section('COMMANDES');
        yield MenuItem::linkTo(OrderCrudController::class, 'Commandes', 'fas fa-shopping-bag');
        yield MenuItem::section('CATALOGUE');
        yield MenuItem::linkTo(ProductCrudController::class, 'Produits', 'fas fa-wine-bottle');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fas fa-tags');
        yield MenuItem::section('COMMERCIAL');
        yield MenuItem::linkTo(PromoCodeCrudController::class, 'Codes promo', 'fas fa-ticket');
        yield MenuItem::linkTo(CommercialSettingsCrudController::class, 'Configuration commerciale', 'fas fa-sliders')->setAction('edit')->setEntityId(1);
        yield MenuItem::section('LIVRAISON');
        yield MenuItem::linkTo(EmballageCrudController::class, 'Emballages', 'fas fa-box');
        yield MenuItem::section('CONTENU');
        yield MenuItem::linkTo(HomepageContentCrudController::class, 'Accueil du site', 'fas fa-house');
        yield MenuItem::linkTo(HeaderCrudController::class, 'Bannières', 'fas fa-images');
        yield MenuItem::section('ADMINISTRATION');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fas fa-users');
    }
}
