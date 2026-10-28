<?php
namespace App\Controller\Admin;
use App\Service\HomeContent;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[IsGranted('ROLE_ADMIN')]
final class HomeContentController extends AbstractController
{
    #[Route('/admin/accueil-du-site', name: 'admin_home_content')]
    public function index(HomeContent $content, AdminUrlGenerator $urls, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $entity = $content->initialize();
        $url = $urls->unsetAll()->setController(HomePageContentCrudController::class)->setAction('edit')->setEntityId($entity->getId())->generateUrl();
        return $this->redirect($url.($request->query->get('bloc') === 'revendeurs' ? '#tab-nos-revendeurs' : ''));
    }
}
