<?php
namespace App\Tests;

use App\Entity\{AccountToken, User};
use App\Service\AccountAccess;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Classe\Mail;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountAccessTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $user;
    private array $emails = [];
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $mail = $this->createMock(Mail::class);
        $mail->method('sendEmail')->willReturnCallback(function ($email) { $this->emails[] = $email; return ['message_id' => 'mock-only']; });
        static::getContainer()->set(Mail::class, $mail);
        foreach (['reset:', 'verify:'] as $prefix) { static::getContainer()->get('limiter.account_requests')->create($prefix.'127.0.0.1')->reset(); }
        $this->user = (new User())->setEmail('client@example.test')->setFirstname('Julie')->setLastname('Martin');
        $this->user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'MotDePasseSolide123!'));
        $this->em->persist($this->user); $this->em->flush();
    }
    private function access(): AccountAccess { return static::getContainer()->get(AccountAccess::class); }
    private function link(string $purpose): string
    {
        $this->access()->issue($this->user, $purpose);
        $email = end($this->emails);
        self::assertInstanceOf(TemplatedEmail::class, $email);
        $dom = new \DOMDocument(); @$dom->loadHTML($email->getHtmlBody());
        $link = (new \DOMXPath($dom))->evaluate('string(//a/@href)');
        self::assertNotEmpty($link);
        return parse_url($link, PHP_URL_PATH);
    }
    private function register(array $changes = []): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Créer mon compte', array_replace([
            'register_user[email]' => 'new@example.test', 'register_user[firstname]' => 'Paul', 'register_user[lastname]' => 'Martin',
            'register_user[plainPassword][first]' => 'UnAutreMotDePasse123!', 'register_user[plainPassword][second]' => 'UnAutreMotDePasse123!',
        ], $changes));
    }
    public function testValidLoginAndNavigation(): void
    {
        $this->client->request('GET', '/connexion');
        self::assertSelectorExists('a[href="/mot-de-passe-oublie"]');
        self::assertSelectorExists('input[type="email"][autocomplete="username"]');
        self::assertSelectorExists('button[aria-controls="password"][aria-pressed="false"]');
        $this->client->submitForm('Me connecter', ['_username' => $this->user->getEmail(), '_password' => 'MotDePasseSolide123!']);
        self::assertResponseRedirects('/compte'); $this->client->followRedirect(); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav[aria-label="Navigation de l’espace client"]', 'Mes commandes');
        self::assertSelectorTextContains('nav[aria-label="Navigation de l’espace client"]', 'Mes adresses');
        self::assertSelectorTextContains('aside', 'Adresse email à vérifier');
    }
    public function testInvalidPassword(): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Me connecter', ['_username' => $this->user->getEmail(), '_password' => 'wrong']);
        self::assertResponseRedirects('/connexion'); $this->client->followRedirect(); self::assertSelectorExists('.alert-danger');
    }
    public function testRegistrationHashesPasswordAndSendsWelcomeAndVerification(): void
    {
        $this->register(); self::assertResponseRedirects('/connexion'); self::assertCount(2, $this->emails);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.test']);
        self::assertNotSame('UnAutreMotDePasse123!', $user->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'UnAutreMotDePasse123!'));
        self::assertNull($user->getEmailVerifiedAt());
    }
    public function testDuplicateEmailHasReadableError(): void
    {
        $this->register(['register_user[email]' => $this->user->getEmail()]);
        self::assertSelectorTextContains('.auth-card', 'Cette adresse e-mail est déjà utilisée.'); self::assertCount(0, $this->emails);
    }
    public function testRegistrationMismatchAndShortPasswordAreRejected(): void
    {
        $this->register(['register_user[plainPassword][second]' => 'different']);
        self::assertSelectorTextContains('.auth-card', 'Les deux mots de passe doivent être identiques.');
        $this->register(['register_user[plainPassword][first]' => 'short', 'register_user[plainPassword][second]' => 'short']);
        self::assertSelectorExists('.invalid-feedback'); self::assertCount(0, $this->emails);
    }
    public function testForgotPasswordIsGenericAndOnlyKnownUserGetsEmail(): void
    {
        foreach (['absent@example.test', $this->user->getEmail()] as $email) {
            $this->client->request('GET', '/mot-de-passe-oublie');
            $this->client->submitForm('Envoyer le lien', ['form[email]' => $email]);
            self::assertResponseRedirects('/mot-de-passe-oublie'); $this->client->followRedirect();
            self::assertSelectorTextContains('.alert-success', \App\Controller\AccountAccessController::MESSAGE);
        }
        self::assertCount(1, $this->emails);
    }
    public function testForgotPasswordCsrfAndEmailValidation(): void
    {
        $this->client->request('POST', '/mot-de-passe-oublie', ['form' => ['email' => $this->user->getEmail(), '_token' => 'invalid']]);
        self::assertSelectorExists('.alert-danger'); self::assertCount(0, $this->emails);
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Envoyer le lien', ['form[email]' => 'invalid']);
        self::assertSelectorExists('.invalid-feedback'); self::assertCount(0, $this->emails);
    }
    public function testInvalidAndExpiredTokens(): void
    {
        $this->client->request('GET', '/reinitialiser-mot-de-passe/invalid'); self::assertResponseStatusCodeSame(400);
        $path = $this->link('reset');
        $token = $this->em->getRepository(AccountToken::class)->findOneBy([]);
        (new \ReflectionProperty($token, 'expiresAt'))->setValue($token, new \DateTimeImmutable('-1 second')); $this->em->flush();
        $this->client->request('GET', $path); self::assertResponseStatusCodeSame(400);
    }
    public function testResetSuccessConsumesTokenAndSendsConfirmation(): void
    {
        $path = $this->link('reset'); $raw = basename($path);
        self::assertSame(hash('sha256', $raw), $this->em->getConnection()->fetchOne('SELECT token_hash FROM account_token'));
        $this->client->request('GET', $path); self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorExists('input[autocomplete="new-password"]');
        $this->client->submitForm('Modifier mon mot de passe', ['reset_password[password][first]' => 'NouveauMotDePasse123!', 'reset_password[password][second]' => 'NouveauMotDePasse123!']);
        self::assertResponseRedirects('/connexion'); $this->user = $this->em->find(User::class, $this->user->getId());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->user, 'NouveauMotDePasse123!'));
        self::assertSame(0, $this->em->getRepository(AccountToken::class)->count([]));
        self::assertSame('Votre mot de passe a été modifié', end($this->emails)->getSubject());
        $this->client->request('GET', $path); self::assertResponseStatusCodeSame(400);
    }
    public function testResetCsrfAndMismatchDoNotConsumeToken(): void
    {
        $path = $this->link('reset');
        $this->client->request('POST', $path, ['reset_password' => ['password' => ['first' => 'NouveauMotDePasse123!', 'second' => 'NouveauMotDePasse123!'], '_token' => 'invalid']]);
        self::assertSelectorExists('.alert-danger');
        $this->client->request('GET', $path);
        $this->client->submitForm('Modifier mon mot de passe', ['reset_password[password][first]' => 'NouveauMotDePasse123!', 'reset_password[password][second]' => 'DifferentMotDePasse123!']);
        self::assertSelectorExists('.invalid-feedback'); self::assertSame(1, $this->em->getRepository(AccountToken::class)->count([]));
    }
    public function testVerificationRequiresCsrfConfirmationAndIsSingleUse(): void
    {
        $path = $this->link('verify');
        $this->client->request('GET', $path); self::assertResponseIsSuccessful(); self::assertNull($this->user->getEmailVerifiedAt());
        $this->client->request('POST', $path, ['form' => ['_token' => 'invalid']]); self::assertNull($this->user->getEmailVerifiedAt());
        $this->client->request('GET', $path); $this->client->submitForm('Confirmer mon adresse email');
        self::assertResponseRedirects('/connexion'); $this->user = $this->em->find(User::class, $this->user->getId()); self::assertNotNull($this->user->getEmailVerifiedAt());
        $this->client->request('GET', $path); self::assertResponseStatusCodeSame(400);
    }
    public function testResendInvalidatesOldLinkAndEmailChangeInvalidatesVerification(): void
    {
        $old = $this->link('verify'); $new = $this->link('verify'); self::assertNotSame($old, $new);
        $this->client->request('GET', $old); self::assertResponseStatusCodeSame(400);
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->user->setEmail('different@example.test'); $this->em->flush();
        $this->client->request('GET', $new); self::assertResponseStatusCodeSame(400);
    }
    public function testExistingPasswordChangeInvalidatesResetLink(): void
    {
        $path = $this->link('reset'); $this->user->setPassword('different-hash'); $this->em->flush();
        $this->client->request('GET', $path); self::assertResponseStatusCodeSame(400);
    }

    public function testVerificationExpiryAndPurposeIsolation(): void
    {
        $path = $this->link('verify');
        self::assertNull($this->access()->find(basename($path), 'reset'));
        $token = $this->em->getRepository(AccountToken::class)->findOneBy([]);
        (new \ReflectionProperty($token, 'expiresAt'))->setValue($token, new \DateTimeImmutable('-1 second')); $this->em->flush();
        $this->client->request('GET', $path); self::assertResponseStatusCodeSame(400);
        self::assertNull($this->user->getEmailVerifiedAt());
    }

    public function testForgotPasswordThrottlingKeepsGenericResponse(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->client->request('GET', '/mot-de-passe-oublie');
            $this->client->submitForm('Envoyer le lien', ['form[email]' => $this->user->getEmail()]);
            self::assertResponseRedirects('/mot-de-passe-oublie');
        }
        self::assertCount(5, $this->emails);
        self::assertSame(1, $this->em->getRepository(AccountToken::class)->count([]));
        $this->client->followRedirect(); self::assertSelectorTextContains('.alert-success', \App\Controller\AccountAccessController::MESSAGE);
    }

    public function testEmailResendRequiresLoginAndCsrf(): void
    {
        $this->client->request('POST', '/compte/renvoyer-verification'); self::assertResponseRedirects('/connexion');
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/compte/renvoyer-verification', ['_token' => 'invalid']); self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/compte');
        $this->client->submitForm('Recevoir le lien de vérification'); self::assertResponseRedirects('/compte'); self::assertCount(1, $this->emails);
    }
    public function testNoLegacyDeliveryAccessInClientTemplate(): void
    {
        $request = \Symfony\Component\HttpFoundation\Request::create('/commande/sendcloud');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        $stack = static::getContainer()->get('request_stack'); $stack->push($request);
        try { $html = static::getContainer()->get('twig')->render('order/sendcloud.html.twig', ['offers' => [], 'selected' => null, 'error' => null, 'address' => null, 'cart' => [], 'totalWt' => 0, 'step' => 'address', 'addresses' => [], 'legacyFallbackEnabled' => true]); }
        finally { $stack->pop(); }
        self::assertStringNotContainsString('Utiliser la livraison classique', $html);
        self::assertStringNotContainsString('Solution de secours', $html);
        self::assertStringNotContainsString('fallback=1', $html);
    }

    public function testMailjetReceiptLogsNeverContainTokensOrRecipient(): void
    {
        $raw = str_repeat('a', 64); $link = 'https://example.test/reinitialiser-mot-de-passe/'.$raw;
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('info')->willReturnCallback(function ($message, $context) use ($raw, $link) {
            $logged = $message.json_encode($context);
            self::assertStringContainsString('accepté par Mailjet', $logged);
            self::assertStringNotContainsString($raw, $logged);
            self::assertStringNotContainsString($link, $logged);
            self::assertStringNotContainsString('secret-password', $logged);
            self::assertStringNotContainsString($this->user->getEmail(), $logged);
            self::assertSame('reset', $context['kind']);
            self::assertSame('mock-only', $context['message_id']);
        });
        $access = new AccountAccess($this->em, static::getContainer()->get(Mail::class),
            static::getContainer()->get(UserPasswordHasherInterface::class), static::getContainer()->get('router'),
            $logger, 'https://example.test', 'swiftfacts9@gmail.com', static::getContainer()->get(BodyRendererInterface::class));
        $access->send($this->user, 'reset', $link);
        self::assertCount(1, $this->emails);
    }

    public function testAllFourAccountEmailsHaveRenderedHtmlAndText(): void
    {
        foreach (['welcome', 'verify', 'reset', 'changed'] as $kind) {
            $link = in_array($kind, ['reset', 'verify'], true) ? 'https://configured.example.test/test-link' : null;
            $this->access()->send($this->user, $kind, $link);
            $email = end($this->emails);
            self::assertNotEmpty($email->getHtmlBody()); self::assertNotEmpty($email->getTextBody());
            self::assertStringContainsString('Elixquis', $email->getHtmlBody());
            self::assertSame($this->user->getEmail(), $email->getTo()[0]->getAddress());
            self::assertSame('swiftfacts9@gmail.com', $email->getFrom()[0]->getAddress());
            self::assertSame('Elixquis', $email->getFrom()[0]->getName());
        }
        self::assertCount(4, $this->emails);
    }

    public function testConfiguredAuthBaseUrlIsUsedForBothTokenPurposes(): void
    {
        $access = new AccountAccess($this->em, static::getContainer()->get(Mail::class),
            static::getContainer()->get(UserPasswordHasherInterface::class), static::getContainer()->get('router'),
            new \Psr\Log\NullLogger(), 'https://configured.example.test', 'swiftfacts9@gmail.com',
            static::getContainer()->get(BodyRendererInterface::class));
        foreach (['reset', 'verify'] as $purpose) {
            $access->issue($this->user, $purpose);
            $html = end($this->emails)->getHtmlBody();
            self::assertStringContainsString('href="https://configured.example.test/', $html);
            self::assertStringNotContainsString('127.0.0.1', $html);
        }
    }

    public function testMailjetFailureIsGenericAndDoesNotLeakProviderException(): void
    {
        $mail = $this->createMock(Mail::class);
        $mail->method('sendEmail')->willThrowException(new \App\Classe\MailDeliveryException('generic failure'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Courriel espace client indisponible.', ['kind' => 'reset']);
        $access = new AccountAccess($this->em, $mail,
            static::getContainer()->get(UserPasswordHasherInterface::class), static::getContainer()->get('router'),
            $logger, 'https://configured.example.test', 'swiftfacts9@gmail.com', static::getContainer()->get(BodyRendererInterface::class));
        $access->send($this->user, 'reset', 'https://configured.example.test/secret-token');
    }
}
