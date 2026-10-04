<?php

namespace App\Controller\Account;

use App\Form\ProfileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    #[Route('/compte/informations', name: 'app_account_profile', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProfileType::class, $this->getUser())->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush(); $this->addFlash('success', 'Vos informations ont été mises à jour.');
            return $this->redirectToRoute('app_account_profile');
        }
        return $this->render('account/profile.html.twig', ['profileForm' => $form->createView()]);
    }
}
