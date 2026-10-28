<?php
namespace App\Controller;

use App\Entity\Revendeur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RevendeurController extends AbstractController
{
    #[Route('/nos-revendeurs', name: 'app_revendeurs', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('revendeur/index.html.twig', [
            'revendeurs' => $em->getRepository(Revendeur::class)->findBy(['actif' => true], ['priorite' => 'ASC', 'nom' => 'ASC']),
        ]);
    }
}
