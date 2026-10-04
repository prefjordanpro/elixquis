<?php

namespace App\Controller;

use App\Classe\Mail;
use App\Entity\User;
use App\Form\RegisterUserType;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class RegisterController extends AbstractController
{
    #[Route('/inscription', name: 'app_register')]
    public function index(Request $request, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
    {
        $user = new User();

        $form = $this->createForm(RegisterUserType::class, $user);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash(
            'success',
            "Votre compte est correctement créé, veuillez vous connecter"
            );

            // Envoie d'un email de confirmation d'inscription
            $mail = new Mail();
            $vars = [
                'firstname' => $user->getFirstname(),
            ];
            if ($this->getParameter('kernel.environment') !== 'test') {
                try { $mail->send($user->getEmail(), $user->getFirstname().' '.$user->getLastname(), "Bienvenue sur Elixquis - Le rhum 100% Français", "welcome.html", $vars); }
                catch (\Throwable $e) { $logger->warning('Le courriel de bienvenue n’a pas pu être envoyé.', ['type' => $e::class]); }
            }


            return $this->redirectToRoute('app_login');
        }

        return $this->render('register/index.html.twig', [
            'registerForm' => $form->createView()
        ]);
    }
}
