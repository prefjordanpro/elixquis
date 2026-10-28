<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\LegalVersion;
use App\Service\{LegalContent, LegalMarkup};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route('/conditions-generales-de-vente', name: 'app_legal_cgv', defaults: ['type' => 'cgv'], methods: ['GET'])]
    #[Route('/mentions-legales', name: 'app_legal_mentions', defaults: ['type' => 'mentions'], methods: ['GET'])]
    #[Route('/politique-de-confidentialite', name: 'app_legal_confidentialite', defaults: ['type' => 'confidentialite'], methods: ['GET'])]
    #[Route('/politique-cookies', name: 'app_legal_cookies', defaults: ['type' => 'cookies'], methods: ['GET'])]
    public function page(string $type, LegalContent $content, LegalMarkup $markup): Response
    {
        $version = $content->published($type);
        if (!$version) throw $this->createNotFoundException();
        return $this->render('legal/page.html.twig', ['version' => $version, 'body' => $markup->render($version->getContent())]);
    }
    #[Route('/conditions-generales-de-vente/version/{id}', name: 'app_legal_cgv_version', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function version(int $id, EntityManagerInterface $em, LegalMarkup $markup): Response
    {
        $version = $em->find(LegalVersion::class, $id);
        if (!$version || $version->getPage()->getId() !== 'cgv' || !$markup->isComplete($version->getContent())) throw $this->createNotFoundException();
        return $this->render('legal/page.html.twig', ['version' => $version, 'body' => $markup->render($version->getContent())]);
    }
    #[Route('/retractation', name: 'app_legal_withdrawal', methods: ['GET'])]
    public function withdrawal(LegalContent $content): Response
    {
        if (!$content->published('cgv')) throw $this->createNotFoundException();
        return $this->render('legal/withdrawal.html.twig');
    }
}
