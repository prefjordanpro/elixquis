<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{Address, Carrier, LegalPage, LegalVersion, Order, Product, User};
use App\Service\{LegalContent, LegalMarkup, OrderManager};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalPagesTest extends WebTestCase
{
    use \App\Tests\Support\LegalTerms;
    private $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $user;
    private Address $address;
    private Carrier $carrier;
    private Product $product;

    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new User())->setEmail('legal-admin@example.test')->setFirstname('Paul')->setLastname('Test')->setPassword('unused')->setRoles(['ROLE_ADMIN']);
        $this->user = (new User())->setEmail('legal-client@example.test')->setFirstname('Client')->setLastname('Test')->setPassword('unused');
        $this->address = (new Address())->setUser($this->user)->setFirstname('Client')->setLastname('Test')->setAddress('1 rue des Tests')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0600000000');
        $this->carrier = (new Carrier())->setName('Livraison de test')->setDescription('Domicile')->setPrice(5)->setTva(20);
        $category = (new \App\Entity\Category())->setName('Saveur visible');
        $this->product = (new Product())->setName('Recette légale test')->setPrice(20)->setTva(20)->setStock(5)->setDescription('Produit de test')->setIllustration('test.jpg')->setCategory($category);
        foreach ([$this->admin, $this->user, $this->address, $this->carrier, $category, $this->product] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }
    private function content(): LegalContent { return static::getContainer()->get(LegalContent::class); }
    private function page(string $type = 'cgv'): LegalPage { $this->content()->initialize(); return $this->em->find(LegalPage::class, $type); }
    private function export(string $name): void
    {
        if ($directory = getenv('LEGAL_REVIEW_DIR')) { if (!is_dir($directory)) mkdir($directory, 0777, true); file_put_contents($directory.'/'.$name.'.html', $this->client->getResponse()->getContent()); }
    }
    private function checkout(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/produit/'.$this->product->getSlug());
        $this->client->submit($crawler->selectButton('Ajouter au panier')->form());
        return $this->client->request('GET', '/commande/livraison');
    }
    private function savedDraft(string $html = '<h2>Conditions de test</h2><p>Document complet réservé aux tests.</p>'): LegalPage
    {
        $page = $this->page(); $this->content()->saveDraft($page, 'Conditions de test', $html, $page->getRevision());
        return $page;
    }
    public function testAdminSeesFourDocumentsAndTheExactInitialDraft(): void
    {
        $this->client->loginUser($this->admin); $crawler = $this->client->request('GET', '/admin/pages-legales');
        self::assertResponseIsSuccessful(); self::assertCount(4, $crawler->filter('.workspace-panel'));
        self::assertSelectorTextContains('.content-body', 'À compléter avant publication définitive');
        $page = $this->em->find(LegalPage::class, 'cgv');
        self::assertStringContainsString('[À COMPLÉTER — MÉDIATEUR DE LA CONSOMMATION]', $page->getDraftContent());
        self::assertStringContainsString('[À INSÉRER — ENCADRÉ RÉGLEMENTAIRE OFFICIEL DES GARANTIES LÉGALES]', $page->getDraftContent());
        self::assertStringContainsString('## 16. Modification et conservation des CGV', file_get_contents(dirname(__DIR__).'/migrations/data/cgv-initial-20261028.txt'));
        foreach (['mentions','confidentialite','cookies'] as $type) self::assertSame('', $this->em->find(LegalPage::class, $type)->getDraftContent());
        self::assertSame(0, $this->em->getRepository(LegalVersion::class)->count([])); $this->export('legal-admin');
        $this->client->request('GET', '/admin/pages-legales/cgv/modifier'); self::assertResponseIsSuccessful();
        self::assertSelectorExists('trix-editor'); self::assertSelectorTextContains('.content-body', 'Vérifiez attentivement toute modification'); $this->export('legal-edit');
        $this->client->request('GET', '/admin/pages-legales/cgv/apercu'); self::assertResponseIsSuccessful(); $this->export('legal-preview');
    }
    public function testInitialHtmlPreservesEveryWordOfTheSuppliedText(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/migrations/data/cgv-initial-20261028.txt');
        $source = preg_replace('/^(?:#{1,3} |\- )/m', '', $source);
        $html = file_get_contents(dirname(__DIR__).'/migrations/data/cgv-initial-20261028.html');
        $text = 'CONDITIONS GÉNÉRALES DE VENTE '.html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</h2>', '</h3>', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalize = static fn ($text) => trim(preg_replace('/\s+/u', ' ', $text));
        self::assertSame($normalize($source), $normalize($text));
    }
    public function testNormalUsersCannotManageOrPreviewDocuments(): void
    {
        $this->client->loginUser($this->user);
        foreach (['/admin/pages-legales', '/admin/pages-legales/cgv/modifier', '/admin/pages-legales/cgv/apercu', '/admin/pages-legales/cgv/historique', '/admin/pages-legales/cgv/versions/1'] as $url) {
            $this->client->request('GET', $url); self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/admin/pages-legales/cgv/publier'); self::assertResponseStatusCodeSame(403);
    }
    public function testDraftSaveAndUnsavedPreviewNeverPublish(): void
    {
        $this->client->loginUser($this->admin); $crawler = $this->client->request('GET', '/admin/pages-legales/cgv/modifier');
        $form = $crawler->selectButton('Enregistrer le brouillon')->form(['legal_document[title]'=>'Titre enregistré', 'legal_document[body]'=>'<p>Texte enregistré.</p>']);
        $this->client->submit($form); self::assertResponseRedirects();
        $page = $this->em->find(LegalPage::class, 'cgv'); self::assertSame('Titre enregistré', $page->getDraftTitle()); self::assertNull($page->getPublishedVersion());
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Prévisualiser')->form(['legal_document[body]'=>'<p>Texte non enregistré.</p>']);
        $this->client->submit($form); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('.legal-reading', 'Texte non enregistré');
        $page = $this->em->find(LegalPage::class, 'cgv');
        $this->em->refresh($page); self::assertStringNotContainsString('non enregistré', $page->getDraftContent());
        $this->client->request('GET', '/conditions-generales-de-vente'); self::assertResponseStatusCodeSame(404);
    }
    public function testPublishingRequiresConfirmationAndRejectsIncompleteDraft(): void
    {
        $this->client->loginUser($this->admin); $crawler = $this->client->request('GET', '/admin/pages-legales/cgv/modifier');
        $this->client->request('POST', '/admin/pages-legales/cgv/publier', ['confirmed'=>'1', 'revision'=>1]); self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/admin/pages-legales/cgv/modifier');
        $form = $crawler->filter('form[action$="/publier"]')->form(); $values = $form->getPhpValues(); $values['confirmed'] = '1';
        $this->client->request('POST', $form->getUri(), $values); self::assertResponseRedirects();
        self::assertSame(0, $this->em->getRepository(LegalVersion::class)->count([]));
        $this->client->followRedirect(); self::assertSelectorTextContains('.content-body', 'À compléter avant publication définitive');
    }
    public function testPublishCreatesAnImmutableSnapshotAndOnlyOneCurrentVersion(): void
    {
        $page = $this->savedDraft('<p>Version en vigueur au : [DATE DE PUBLICATION]</p><p>Première rédaction de test.</p>');
        $first = $this->content()->publish($page, $page->getRevision(), $this->admin);
        self::assertSame(1, $first->getNumber()); self::assertStringNotContainsString('[DATE DE PUBLICATION]', $first->getContent());
        self::assertSame($this->admin, $first->getPublishedBy());
        $this->content()->saveDraft($page, 'Nouvelle rédaction', '<h2>Nouvelle rédaction</h2><p>Deuxième rédaction de test.</p>', $page->getRevision());
        $this->client->request('GET', '/conditions-generales-de-vente'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('.legal-document-body', 'Première rédaction');
        self::assertSelectorTextNotContains('.legal-document-body', 'Deuxième rédaction');
        $second = $this->content()->publish($page, $page->getRevision(), $this->admin);
        self::assertSame(2, $second->getNumber()); self::assertFalse($first->isCurrent()); self::assertTrue($second->isCurrent());
        self::assertSame('Archivée', $first->getStatus()); self::assertSame(2, $this->em->getRepository(LegalVersion::class)->count([]));
        $this->client->request('GET', '/conditions-generales-de-vente'); self::assertSelectorTextContains('.legal-document-body', 'Deuxième rédaction'); $this->export('legal-public');
        $this->client->request('GET', '/conditions-generales-de-vente/version/'.$first->getId()); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('.legal-document-body', 'Première rédaction');
        $this->client->loginUser($this->admin); $this->client->request('GET', '/admin/pages-legales/cgv/historique'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('.content-body', 'Version 1'); $this->export('legal-history');
        $this->client->request('GET', '/admin/pages-legales/cgv/versions/'.$first->getId()); self::assertResponseIsSuccessful(); self::assertSelectorNotExists('form');
    }
    public function testPublishedVersionCannotBeDeletedThroughDoctrine(): void
    {
        $version = $this->publishTestTerms($this->em); $this->em->remove($version);
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('lecture seule'); $this->em->flush();
    }
    public function testAdminCanPublishTheSavedDraftThroughTheConfirmedForm(): void
    {
        $this->savedDraft(); $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/pages-legales/cgv/modifier');
        $form = $crawler->selectButton('Publier')->form(['confirmed'=>'1']);
        $this->client->submit($form); self::assertResponseRedirects();
        $page = $this->em->find(LegalPage::class, 'cgv');
        self::assertNotNull($page->getPublishedVersion()); self::assertSame($this->admin->getId(), $page->getPublishedVersion()->getPublishedBy()->getId());
        $this->client->request('GET', '/conditions-generales-de-vente'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.legal-document-body', 'Document complet réservé aux tests.');
    }
    public function testPublishedContentCannotBeChangedThroughDoctrine(): void
    {
        $version = $this->publishTestTerms($this->em); (new \ReflectionProperty(LegalVersion::class, 'content'))->setValue($version, '<p>Modification interdite</p>');
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('lecture seule'); $this->em->flush();
    }
    public function testStaleDraftRevisionIsRejected(): void
    {
        $page = $this->savedDraft(); $revision = $page->getRevision();
        $this->content()->saveDraft($page, 'Premier enregistrement', '<p>Premier enregistrement.</p>', $revision);
        $this->expectException(\Doctrine\ORM\OptimisticLockException::class);
        $this->content()->saveDraft($page, 'Enregistrement périmé', '<p>À ne pas écraser.</p>', $revision);
    }
    public function testDangerousMarkupAndTwigAreRemovedServerSide(): void
    {
        $page = $this->savedDraft('<h1>Titre</h1><p style="color:red" onclick="alert(1)">Texte <strong>gras</strong> <em>italique</em>.</p><script>alert(1)</script><style>body{display:none}</style><iframe src="https://example.test"></iframe><a href="javascript:alert(1)">Lien interdit</a><a href="https://example.test">Lien valide</a><p>{{ app.secret }} {% include "base.html.twig" %}</p>');
        foreach (['<script','<iframe','<style','onclick=','style=','javascript:','app.secret','include'] as $unsafe) self::assertStringNotContainsString($unsafe, $page->getDraftContent());
        self::assertStringContainsString('<h2>Titre</h2>', $page->getDraftContent());
        self::assertStringContainsString('<strong>gras</strong>', $page->getDraftContent()); self::assertStringContainsString('https://example.test', $page->getDraftContent());
        $this->content()->publish($page, $page->getRevision(), $this->admin);
        $this->client->request('GET', '/conditions-generales-de-vente'); self::assertSelectorNotExists('.legal-reading script'); self::assertSelectorNotExists('.legal-reading iframe');
    }
    public function testFooterHasNoPlaceholdersOrDeadLinksAndOnlyPublishedDocuments(): void
    {
        $this->page(); $this->client->request('GET', '/'); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.site-footer a[href="/conditions-generales-de-vente"]');
        self::assertSelectorTextNotContains('.site-footer', 'À compléter'); self::assertSelectorNotExists('.legal-pending');
        foreach (['/mentions-legales','/politique-de-confidentialite','/politique-cookies','/retractation'] as $url) { $this->client->request('GET', $url); self::assertResponseStatusCodeSame(404); }
        $page = $this->savedDraft(); $this->content()->publish($page, $page->getRevision(), $this->admin);
        $crawler = $this->client->request('GET', '/'); self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.footer-grid > div'));
        self::assertSelectorExists('.site-footer a[href="/conditions-generales-de-vente"]');
        self::assertSelectorExists('.site-footer a[href="/retractation"]'); self::assertSelectorNotExists('.site-footer a[href="/mentions-legales"]');
        self::assertSelectorTextContains('.site-footer', 'Vente interdite aux mineurs'); $this->export('footer-published');
        $this->client->request('GET', '/retractation'); self::assertResponseIsSuccessful(); self::assertSelectorNotExists('main form'); self::assertSelectorExists('a[href="/conditions-generales-de-vente#formulaire-retractation"]'); $this->export('withdrawal');
    }
    public function testFooterCategoriesOnlyIncludeVisibleCatalogueProductsWithoutDeletingCategories(): void
    {
        $empty = (new \App\Entity\Category())->setName('Aux bonbons deux'); $this->em->persist($empty); $this->em->flush();
        $this->client->request('GET', '/'); self::assertSelectorTextContains('.site-footer', 'Saveur visible'); self::assertSelectorTextNotContains('.site-footer', 'Aux bonbons deux');
        self::assertNotNull($this->em->find(\App\Entity\Category::class, $empty->getId()));
    }
    public function testEachDocumentOnlyBecomesPublicAfterItsOwnPublication(): void
    {
        $this->page(); $page = $this->em->find(LegalPage::class, 'confidentialite');
        $this->content()->saveDraft($page, $page->getLabel(), '<p>Document fictif réservé au test de publication.</p>', $page->getRevision());
        $this->client->request('GET', '/politique-de-confidentialite'); self::assertResponseStatusCodeSame(404);
        $page = $this->em->find(LegalPage::class, 'confidentialite'); $this->content()->publish($page, $page->getRevision(), null);
        $this->client->request('GET', '/politique-de-confidentialite'); self::assertResponseIsSuccessful();
        $this->client->request('GET', '/'); self::assertSelectorExists('.site-footer a[href="/politique-de-confidentialite"]');
        self::assertSelectorNotExists('.site-footer a[href="/mentions-legales"]'); self::assertSelectorNotExists('.site-footer a[href="/politique-cookies"]');
    }
    public function testOversizedMultibyteDocumentsAreRejectedInsteadOfSilentlyTruncated(): void
    {
        $this->expectException(\DomainException::class); $this->expectExceptionMessage('trop long');
        static::getContainer()->get(LegalMarkup::class)->clean(str_repeat('é', 100001));
    }
    public function testCheckoutCheckboxIsRequiredUncheckedAndVersionSpecific(): void
    {
        $version = $this->publishTestTerms($this->em); $crawler = $this->checkout(); self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="accept_terms"][required]:not([checked])'); self::assertSame((string) $version->getId(), $crawler->filter('input[name="terms_version"]')->attr('value'));
        self::assertSelectorExists('a[href="/conditions-generales-de-vente/version/'.$version->getId().'"]'); $this->export('legal-checkout');
    }
    public function testMissingAcceptanceCannotCreateOrderOrConsumeStock(): void
    {
        $this->publishTestTerms($this->em); $crawler = $this->checkout();
        $form = $crawler->filter('form[name="order"]')->form(['order[addresses]'=>$this->address->getId(), 'order[carriers]'=>$this->carrier->getId()]);
        $this->client->submit($form); self::assertResponseRedirects('/mon-panier');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order`'));
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT stock FROM product WHERE id = ?', [$this->product->getId()]));
    }
    public function testOrderStoresTheAcceptedVersionAndKeepsItAfterANewPublication(): void
    {
        $first = $this->publishTestTerms($this->em); $crawler = $this->checkout();
        $form = $crawler->filter('form[name="order"]')->form(['order[addresses]'=>$this->address->getId(), 'order[carriers]'=>$this->carrier->getId(), 'accept_terms'=>'1']);
        $this->client->submit($form); self::assertResponseRedirects();
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        self::assertNotNull($order); self::assertSame($first->getId(), $order->getAcceptedTermsVersion()->getId()); self::assertNotNull($order->getTermsAcceptedAt());
        $total = $order->getTotalCents(); $page = $this->em->find(LegalPage::class, 'cgv');
        $this->content()->saveDraft($page, 'Conditions suivantes', '<p>Nouvelle version de test.</p>', $page->getRevision());
        $second = $this->content()->publish($page, $page->getRevision(), $this->admin);
        self::assertNotSame($second->getId(), $order->getAcceptedTermsVersion()->getId()); self::assertSame($total, $order->getTotalCents());
    }
    public function testStaleAcceptedVersionIsRejectedWhenTermsChangeDuringCheckout(): void
    {
        $this->publishTestTerms($this->em); $crawler = $this->checkout();
        $form = $crawler->filter('form[name="order"]')->form(['order[addresses]'=>$this->address->getId(), 'order[carriers]'=>$this->carrier->getId(), 'accept_terms'=>'1']);
        $page = $this->em->find(LegalPage::class, 'cgv'); $this->content()->saveDraft($page, 'Version suivante', '<p>Conditions suivantes.</p>', $page->getRevision());
        $this->content()->publish($page, $page->getRevision(), $this->admin);
        $this->client->submit($form); self::assertResponseRedirects('/mon-panier');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order`'));
    }
    public function testNoPublishedTermsPreventsForgedCheckoutAndDoesNotExposeInitialMarkers(): void
    {
        $this->page(); $crawler = $this->checkout(); self::assertSelectorNotExists('input[name="accept_terms"]');
        self::assertSelectorTextNotContains('main', '[À COMPLÉTER'); self::assertSelectorExists('main button[type="submit"][disabled]');
        $form = $crawler->filter('form[name="order"]')->form(); $values = $form->getPhpValues();
        $values['order']['addresses'] = $this->address->getId(); $values['order']['carriers'] = $this->carrier->getId(); $values['accept_terms'] = '1'; $values['terms_version'] = '1';
        $this->client->request('POST', $form->getUri(), $values); self::assertResponseRedirects('/mon-panier');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order`'));
    }
    public function testOldOrdersWithoutAcceptedTermsStillRenderAndKeepTheirTotals(): void
    {
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, [['object'=>$this->product, 'qty'=>1]]);
        self::assertNull($order->getAcceptedTermsVersion()); self::assertNull($order->getTermsAcceptedAt());
        $total = $order->getTotalCents(); $this->client->loginUser($this->user);
        $this->client->request('GET', '/compte/commande/'.$order->getId()); self::assertResponseIsSuccessful(); self::assertSame($total, $order->getTotalCents());
    }
}
