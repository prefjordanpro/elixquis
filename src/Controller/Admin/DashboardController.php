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
    public function index(): Response
    {
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        return $this->redirect($adminUrlGenerator->setController(OrderCrudController::class)->generateUrl());
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
        yield MenuItem::section('LIVRAISON');
        yield MenuItem::linkTo(EmballageCrudController::class, 'Emballages', 'fas fa-box');
        yield MenuItem::section('CONTENU');
        yield MenuItem::linkTo(HeaderCrudController::class, 'Bannières', 'fas fa-images');
        yield MenuItem::section('ADMINISTRATION');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fas fa-users');
    }
}
