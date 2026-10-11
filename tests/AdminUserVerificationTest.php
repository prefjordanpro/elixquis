<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminUserVerificationTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $verified;
    private User $unverified;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = $this->user('admin')->setRoles(['ROLE_ADMIN']);
        $this->verified = $this->user('verified');
        $this->verified->verifyEmail();
        $this->unverified = $this->user('unverified');
        $this->em->flush();
        $this->client->loginUser($this->admin);
    }

    private function user(string $name): User
    {
        $user = (new User())->setEmail($name.'@example.test')->setFirstname($name)->setLastname('Test')->setPassword('unused-test-hash');
        $this->em->persist($user);
        return $user;
    }

    public function testIndexAndDetailDisplayVerificationBadgesAndDate(): void
    {
        $this->client->request('GET', '/admin/user');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('thead', 'Email vérifié');
        self::assertSelectorTextContains('.badge.bg-success', 'Oui');
        self::assertSelectorTextContains('.badge.bg-secondary', 'Non');
        foreach ([$this->verified, $this->unverified] as $user) {
            $this->client->request('GET', '/admin/user/'.$user->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $user->getEmail());
            self::assertSelectorTextContains('.badge', $user->getEmailVerificationStatus());
            self::assertSelectorTextContains('body', 'Date de vérification');
            if ($user->getEmailVerifiedAt() !== null) {
                self::assertSelectorTextContains('body', $user->getEmailVerifiedAt()->format('d/m/Y H:i'));
            }
        }
    }

    public function testEditCannotChangeEmailOrVerificationEvenWithForgedSubmission(): void
    {
        foreach ([$this->verified, $this->unverified] as $user) {
            $id = $user->getId();
            $email = $user->getEmail();
            $date = $user->getEmailVerifiedAt()?->format('Y-m-d H:i:s');
            $crawler = $this->client->request('GET', '/admin/user/'.$id.'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[name="User[email]"][disabled]');
            self::assertSelectorNotExists('[name="User[emailVerificationStatus]"]');
            self::assertSelectorNotExists('[name="User[emailVerifiedAt]"]');
            $form = $crawler->filter('form[name="User"]')->form();
            $values = $form->getPhpValues();
            $values['User']['firstname'] = 'Modifié';
            $values['User']['email'] = 'forged@example.test';
            $values['User']['emailVerificationStatus'] = 'Oui';
            $values['User']['emailVerifiedAt'] = '2020-01-01T12:00';
            $this->client->request('POST', $form->getUri(), $values);
            self::assertResponseRedirects();
            $fresh = $this->em->find(User::class, $id);
            $this->em->refresh($fresh);
            self::assertSame('Modifié', $fresh->getFirstname());
            self::assertSame($email, $fresh->getEmail());
            self::assertSame($date, $fresh->getEmailVerifiedAt()?->format('Y-m-d H:i:s'));
        }
    }

    public function testClientCannotAccessAdminVerificationInformation(): void
    {
        $this->client->loginUser($this->unverified);
        foreach (['/admin/user', '/admin/user/'.$this->verified->getId(), '/admin/user/'.$this->verified->getId().'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testListOpensDetailAndEditIsAnExplicitButton(): void
    {
        $crawler = $this->client->request('GET', '/admin/user');
        $url = $crawler->filter('tr[data-id="'.$this->verified->getId().'"]')->attr('data-default-action-url');
        self::assertStringEndsWith('/admin/user/'.$this->verified->getId(), $url);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'verified Test');
        self::assertSelectorTextContains('body', 'Aucune adresse enregistrée');
        self::assertSelectorNotExists('input[type="password"]');
        $this->client->click($crawler->selectLink('Modifier le client')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="User"]');
    }

    public function testClientOrdersTotalsAddressesAndFilterAreIsolated(): void
    {
        foreach ([1, 2, 3, 5, 0, 4, 6] as $state) {
            $order = (new \App\Entity\Order())->setUser($this->verified)->setCreatedAt(new \DateTime('2026-01-0'.($state + 1)))
                ->setState($state)->setCarrierName('Test')->setCarrierPrice(10)->setDelivery('Test');
            $this->em->persist($order);
        }
        $other = (new \App\Entity\Order())->setUser($this->unverified)->setCreatedAt(new \DateTime('2026-12-01'))
            ->setState(1)->setCarrierName('Test')->setCarrierPrice(900)->setDelivery('Test');
        $this->em->persist($other);
        foreach ([$this->verified, $this->unverified] as $user) {
            $address = (new \App\Entity\Address())->setUser($user)->setFirstname($user->getFirstname())->setLastname('Test')
                ->setAddress($user === $this->verified ? '10 rue du Client' : '99 rue Autre')
                ->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
            $this->em->persist($address);
        }
        $this->em->flush();
        $this->em->refresh($this->verified);
        $this->client->request('GET', '/admin/user');
        self::assertSelectorTextContains('tr[data-id="'.$this->verified->getId().'"] td[data-column="orderCount"]', '7');
        $crawler = $this->client->request('GET', '/admin/user/'.$this->verified->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-client-order-count]', '7');
        self::assertSelectorTextContains('[data-client-paid-total]', '40,00');
        self::assertSelectorTextContains('address', '10 rue du Client');
        self::assertStringNotContainsString('99 rue Autre', $crawler->text());
        self::assertSelectorTextContains('body', '07/01/2026');
        $this->client->click($crawler->selectLink('Voir les commandes de ce client')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(7, 'tbody tr[data-id]');
        self::assertSelectorNotExists('tr[data-id="'.$other->getId().'"]');
    }

    public function testResendUsesExistingMailjetTokensAndProtectsVerifiedUsers(): void
    {
        $mail = $this->createMock(\App\Classe\Mail::class);
        $mail->expects(self::once())->method('sendEmail')->with(self::callback(fn ($email) =>
            $email->getTo()[0]->getAddress() === $this->unverified->getEmail()
            && str_contains($email->getHtmlBody(), '/verifier-email/')))->willReturn(['message_id' => 'test-only']);
        static::getContainer()->set(\App\Classe\Mail::class, $mail);
        $crawler = $this->client->request('GET', '/admin/user/'.$this->unverified->getId());
        $form = $crawler->selectButton("Renvoyer l'email de vérification")->form();
        $this->client->request('POST', $form->getUri(), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/user/'.$this->unverified->getId());
        self::assertCount(1, $this->em->getRepository(\App\Entity\AccountToken::class)->findBy(['user' => $this->unverified, 'purpose' => 'verify']));
        self::assertNull($this->unverified->getEmailVerifiedAt());
        $this->client->request('GET', '/admin/user/'.$this->verified->getId());
        self::assertSelectorNotExists('form[action*="renvoyer-verification"]');
        $stack = static::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken('admin_user_verify_'.$this->verified->getId())->getValue();
            $this->client->getRequest()->getSession()->save();
        }
        finally { $stack->pop(); }
        $this->client->request('POST', '/admin/client/'.$this->verified->getId().'/renvoyer-verification', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertCount(0, $this->em->getRepository(\App\Entity\AccountToken::class)->findBy(['user' => $this->verified]));
        $this->client->loginUser($this->unverified);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues());
        self::assertResponseStatusCodeSame(403);
    }

    public function testPaidTotalRespectsRefundsAndPendingCancellationRequests(): void
    {
        foreach (['pending', 'succeeded', 'failed'] as $refundStatus) {
            $order = (new \App\Entity\Order())->setUser($this->verified)->setCreatedAt(new \DateTime())
                ->setState(1)->setCarrierName('Test')->setCarrierPrice(10)->setDelivery('Test')->setStripeRefundStatus($refundStatus);
            $this->em->persist($order);
        }
        foreach ([0, 1] as $previousState) {
            $order = (new \App\Entity\Order())->setUser($this->verified)->setCreatedAt(new \DateTime())
                ->setState(7)->setCarrierName('Test')->setCarrierPrice(10)->setDelivery('Test');
            $order->addCancellationRequest(new \App\Entity\CancellationRequest($order, null, $previousState));
            $this->em->persist($order);
        }
        $this->em->flush();
        $this->em->refresh($this->verified);
        $this->client->request('GET', '/admin/user/'.$this->verified->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-client-paid-total]', '30,00');
    }
}
