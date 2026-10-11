<?php
namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use App\Service\AccountAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\{Email, NotBlank, Length};

final class AccountAccessController extends AbstractController
{
    public const MESSAGE = 'Si un compte correspond à cette adresse, un email vous sera envoyé. Consultez également vos courriers indésirables.';

    #[Route('/mot-de-passe-oublie', name: 'app_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request, UserRepository $users, AccountAccess $access,
        #[Autowire(service: 'limiter.account_requests')] RateLimiterFactory $limiter): Response
    {
        $form = $this->createFormBuilder()->add('email', EmailType::class, ['label' => 'Votre email',
            'attr' => ['autocomplete' => 'email'], 'constraints' => [new NotBlank(), new Email(), new Length(max: 180)]])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($limiter->create('reset:'.$request->getClientIp())->consume()->isAccepted()) {
                $user = $users->findOneBy(['email' => trim($form->get('email')->getData())]);
                if ($user) { $access->issue($user, 'reset'); }
            }
            $this->addFlash('success', self::MESSAGE);
            return $this->redirectToRoute('app_password_forgot');
        }
        return $this->render('auth/form.html.twig', ['title' => 'Mot de passe oublié', 'intro' => 'Recevez un lien pour choisir un nouveau mot de passe.', 'form' => $form, 'button' => 'Envoyer le lien']);
    }

    #[Route('/reinitialiser-mot-de-passe/{token}', name: 'app_password_reset', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, AccountAccess $access): Response
    {
        if (!$access->find($token, 'reset')) { return $this->invalid(); }
        $form = $this->createForm(ResetPasswordType::class); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try { $access->consume($token, 'reset', $form->get('password')->getData()); }
            catch (\DomainException $e) { return $this->invalid(); }
            $this->addFlash('success', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');
            return $this->redirectToRoute('app_login');
        }
        return $this->render('auth/form.html.twig', ['title' => 'Nouveau mot de passe', 'intro' => 'Choisissez un mot de passe d’au moins 12 caractères.', 'form' => $form, 'button' => 'Modifier mon mot de passe']);
    }

    #[Route('/verifier-email/{token}', name: 'app_email_verify', methods: ['GET', 'POST'])]
    public function verify(string $token, Request $request, AccountAccess $access): Response
    {
        if (!$access->find($token, 'verify')) { return $this->invalid(); }
        $form = $this->createFormBuilder()->getForm(); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try { $access->consume($token, 'verify'); } catch (\DomainException $e) { return $this->invalid(); }
            $this->addFlash('success', 'Votre adresse email est vérifiée.');
            return $this->redirectToRoute($this->getUser() ? 'app_account' : 'app_login');
        }
        return $this->render('auth/form.html.twig', ['title' => 'Vérifier mon adresse email', 'intro' => 'Confirmez votre adresse pour terminer la vérification de votre compte.', 'form' => $form, 'button' => 'Confirmer mon adresse email']);
    }

    #[Route('/compte/renvoyer-verification', name: 'app_email_verify_resend', methods: ['POST'])]
    public function resend(Request $request, AccountAccess $access,
        #[Autowire(service: 'limiter.account_requests')] RateLimiterFactory $limiter): Response
    {
        if (!$this->isCsrfTokenValid('verify_email', $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $user = $this->getUser();
        if ($user instanceof User && !$user->getEmailVerifiedAt() && $limiter->create('verify:'.$request->getClientIp())->consume()->isAccepted()) { $access->issue($user, 'verify'); }
        $this->addFlash('success', 'Si votre adresse reste à vérifier, un lien vous sera envoyé.');
        return $this->redirectToRoute('app_account');
    }

    private function invalid(): Response
    {
        return $this->render('auth/invalid.html.twig', [], new Response(status: 400));
    }
}
