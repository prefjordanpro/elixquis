<?php
namespace App\Service;

use App\Entity\{AccountToken, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use App\Classe\{Mail, MailDeliveryException};
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AccountAccess
{
    public function __construct(private EntityManagerInterface $em, private Mail $mail,
        private UserPasswordHasherInterface $hasher, private UrlGeneratorInterface $urls, private LoggerInterface $logger,
        #[Autowire('%env(default:auth.base_url:AUTH_BASE_URL)%')] private string $baseUrl,
        #[Autowire('%env(default:auth.sender:AUTH_SENDER)%')] private string $sender,
        private BodyRendererInterface $renderer) {}

    public function send(User $user, string $kind, ?string $link = null): void
    {
        $titles = ['welcome' => 'Bienvenue chez Elixquis', 'verify' => 'Vérifiez votre adresse email',
            'reset' => 'Réinitialisez votre mot de passe', 'changed' => 'Votre mot de passe a été modifié'];
        try {
            $email = (new TemplatedEmail())->from(new Address($this->sender, 'Elixquis'))->to($user->getEmail())
                ->subject($titles[$kind])->htmlTemplate('emails/account.html.twig')
                ->context(['firstname' => $user->getFirstname(), 'kind' => $kind, 'title' => $titles[$kind], 'link' => $link]);
            $this->renderer->render($email);
            $receipt = $this->mail->sendEmail($email);
            $this->logger->info('Courriel espace client accepté par Mailjet.', [
                'kind' => $kind, 'account_id' => $user->getId(), 'message_id' => $receipt['message_id'],
            ]);
        } catch (MailDeliveryException $e) {
            $this->logger->warning('Courriel espace client indisponible.', ['kind' => $kind]);
        }
    }

    public function issue(User $user, string $purpose): void
    {
        $raw = bin2hex(random_bytes(32));
        $this->em->wrapInTransaction(function () use ($user, $purpose, $raw): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            foreach ($this->em->getRepository(AccountToken::class)->findBy(['user' => $user, 'purpose' => $purpose]) as $old) { $this->em->remove($old); }
            $this->em->persist(new AccountToken($user, $raw, $purpose));
        });
        $route = $purpose === 'reset' ? 'app_password_reset' : 'app_email_verify';
        // Configured origin, never the untrusted Host header of a reset request.
        $this->send($user, $purpose, rtrim($this->baseUrl, '/').$this->urls->generate($route, ['token' => $raw]));
    }

    public function find(string $raw, string $purpose): ?AccountToken
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $raw)) { return null; }
        $token = $this->em->getRepository(AccountToken::class)->findOneBy(['tokenHash' => hash('sha256', $raw), 'purpose' => $purpose]);
        return $token && $token->isValid() ? $token : null;
    }

    public function consume(string $raw, string $purpose, ?string $password = null): void
    {
        $user = $this->em->wrapInTransaction(function () use ($raw, $purpose, $password): User {
            $token = $this->find($raw, $purpose);
            if (!$token) { throw new \DomainException('Ce lien est invalide ou a expiré.'); }
            $user = $token->getUser();
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            // Reload under the user lock: another request may already have consumed the token.
            try { $this->em->refresh($token, LockMode::PESSIMISTIC_WRITE); }
            catch (\Doctrine\ORM\EntityNotFoundException $e) { throw new \DomainException('Ce lien est invalide ou a expiré.'); }
            if (!$token->isValid()) { throw new \DomainException('Ce lien est invalide ou a expiré.'); }
            if ($purpose === 'reset') { $user->setPassword($this->hasher->hashPassword($user, $password)); }
            else { $user->verifyEmail(); }
            foreach ($this->em->getRepository(AccountToken::class)->findBy(['user' => $user, 'purpose' => $purpose]) as $old) { $this->em->remove($old); }
            return $user;
        });
        if ($purpose === 'reset') { $this->send($user, 'changed'); }
    }
}
