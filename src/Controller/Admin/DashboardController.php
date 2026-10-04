<?php

namespace App\Controller\Admin;

use App\Entity\Carrier;
use App\Entity\Category;
use App\Entity\Header;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
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
    public function index(): Response
    {
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        return $this->redirect($adminUrlGenerator->setController(UserCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Elixquis');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('assets/css/admin-responsive.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fas fa-list');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fas fa-list');
        yield MenuItem::linkTo(ProductCrudController::class, 'Produits', 'fas fa-list');
        yield MenuItem::linkTo(CarrierCrudController::class, 'Transporteurs', 'fas fa-list');
        yield MenuItem::linkTo(OrderCrudController::class, 'Commandes', 'fas fa-list');
        yield MenuItem::linkTo(HeaderCrudController::class, 'Bannières', 'fas fa-list');
    }
}
