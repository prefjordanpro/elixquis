<?php
namespace App\Tests;

use App\Entity\{Address, Carrier, Product, PromoCode, User};
use App\Service\{CommercialPricing, OrderManager};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CommercialPromotionsTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private Product $product;
    private User $user;
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new User())->setEmail('promotion@example.test')->setFirstname('Test')->setLastname('Promotion')->setPassword('hash')->setRoles(['ROLE_ADMIN']);
        $this->product = (new Product())->setName('Recette promotion')->setSlug('recette-promotion')->setDescription('Description de test')->setIllustration('2025-08-30-851cfa871ccd52de3f20d860b2188b7f05abebe4.jpg')->setPrice(20)->setTva(20)->setStock(10)->setIsHomepage(true);
        $this->em->persist($this->user); $this->em->persist($this->product); $this->em->flush();
    }
    private function export(string $name): void
    {
        if ($dir = getenv('UX_EXPORT_DIR')) { if (!is_dir($dir)) { mkdir($dir, 0777, true); } file_put_contents($dir.'/'.$name.'.html', $this->client->getResponse()->getContent()); }
    }
    /** @dataProvider percentages */
    public function testPercentagePriceIsRoundedToCents(int $percent): void
    {
        $this->product->setPromotionPercent($percent);
        self::assertTrue($this->product->isPromotionActive());
        self::assertSame((int) round(2400 * (100 - $percent) / 100), (int) round($this->product->getPriceWt() * 100));
        self::assertSame($percent, $this->product->getPromotionDiscountPercent());
        self::assertCount(0, static::getContainer()->get('validator')->validate($this->product));
    }
    public static function percentages(): array { return array_map(static fn ($value) => [$value], Product::PROMOTION_PERCENTAGES); }
    public function testNoDefaultPromotionAndHistoricalFixedPricePreserved(): void
    {
        self::assertNull($this->product->getPromotionSelection()); self::assertFalse($this->product->isPromotionActive()); self::assertSame(24.0, $this->product->getPriceWt());
        $this->product->setPromotionEnabled(true)->setPromotionPriceCents(1700);
        self::assertSame(-1, $this->product->getPromotionSelection());
        $this->product->setPromotionSelection(-1); self::assertSame(17, $this->product->getPriceWt());
        $this->product->setPromotionSelection(null); self::assertFalse($this->product->isPromotionActive()); self::assertSame(24.0, $this->product->getPriceWt());
    }
    public function testPercentageDatesAndValidation(): void
    {
        $this->product->setPromotionPercent(25); $now = new \DateTimeImmutable('2026-10-18 12:00:00 UTC');
        $this->product->setPromotionStartsAt($now->modify('+1 hour')); self::assertFalse($this->product->isPromotionActive($now));
        $this->product->setPromotionStartsAt($now)->setPromotionEndsAt($now->modify('+1 hour')); self::assertTrue($this->product->isPromotionActive($now));
        $this->product->setPromotionEndsAt($now); self::assertFalse($this->product->isPromotionActive($now));
        $this->product->setPromotionStartsAt(null)->setPromotionEndsAt(null); self::assertTrue($this->product->isPromotionActive($now));
        $this->product->setPromotionPercent(7); self::assertGreaterThan(0, count(static::getContainer()->get('validator')->validate($this->product))); self::assertFalse($this->product->isPromotionActive());
    }
    public function testProductAdminSelectionAndFrontendAreConsistent(): void
    {
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/admin/product/'.$this->product->getId().'/edit'); self::assertResponseIsSuccessful();
        self::assertCount(19, $page->filter('select[name="Product[promotionSelection]"] option'));
        $this->export('promotion-produit-admin');
        $form = $page->filter('form[name="Product"]')->form(['Product[promotionSelection]' => '25']);
        $this->client->submit($form); self::assertResponseRedirects();
        $saved = $this->em->find(Product::class, $this->product->getId()); self::assertSame(25, $saved->getPromotionPercent()); self::assertSame(18, $saved->getPriceWt());
        foreach (['/' => 'promotion-homepage', '/catalogue' => 'promotion-boutique', '/produit/recette-promotion' => 'promotion-fiche'] as $url => $export) {
            $this->client->request('GET', $url); self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.product-promotion-label', '−25 %'); self::assertSelectorTextContains('del', '24,00');
            self::assertSelectorTextContains($url === '/produit/recette-promotion' ? '.detail-price' : '.product-price', '18,00'); $this->export($export);
        }
        $this->client->request('GET', '/admin/product'); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.datagrid', 'Prix normal TTC'); self::assertSelectorTextContains('.datagrid', 'Prix appliqué TTC');
        self::assertSelectorTextContains('.datagrid', '−25 % · Active');
    }
    public function testForgedPercentageIsRejected(): void
    {
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/admin/product/'.$this->product->getId().'/edit');
        $form = $page->filter('form[name="Product"]')->form();
        $values = $form->getPhpValues(); $values['Product']['promotionSelection'] = '99';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertFalse($this->client->getResponse()->isRedirection());
        $this->em->clear(); self::assertNull($this->em->find(Product::class, $this->product->getId())->getPromotionPercent());
    }
    public function testFixedCodeEurosCreateEditAndTypeChange(): void
    {
        $code = (new PromoCode())->setCode('EUROS')->setName('Euros')->setActive(true)->setType('percent')->setValue(10);
        $this->em->persist($code); $this->em->flush(); $id = $code->getId();
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/admin/promo-code/'.$id.'/edit'); $this->export('promotion-code-percent');
        self::assertSelectorTextContains('form[name="PromoCode"]', 'Montant minimum du panier TTC');
        $form = $page->filter('form[name="PromoCode"]')->form(['PromoCode[type]' => 'fixed', 'PromoCode[value]' => '5,25']);
        $this->client->submit($form); self::assertResponseRedirects();
        self::assertSame(525, $this->em->find(PromoCode::class, $id)->getValue());
        $page = $this->client->request('GET', '/admin/promo-code/'.$id.'/edit');
        self::assertSame('5,25', $page->filter('input[name="PromoCode[value]"]')->attr('value')); $this->export('promotion-code-fixed');
        $form = $page->filter('form[name="PromoCode"]')->form(['PromoCode[type]' => 'percent', 'PromoCode[value]' => '15']);
        $this->client->submit($form); self::assertResponseRedirects();
        self::assertSame(15, $this->em->find(PromoCode::class, $id)->getValue());
    }
    public function testPercentageSnapshotAndCouponStacking(): void
    {
        $this->product->setPromotionPercent(25);
        $code = (new PromoCode())->setCode('CUMUL')->setName('Test cumul')->setActive(true)->setType('percent')->setValue(10);
        $this->em->persist($code);
        $address = (new Address())->setUser($this->user)->setFirstname('Test')->setLastname('Test')->setAddress('Test')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $carrier = (new Carrier())->setName('Test')->setDescription('Test')->setPrice(5)->setTva(20);
        $this->em->persist($address); $this->em->persist($carrier); $this->em->flush();
        $pricing = static::getContainer()->get(CommercialPricing::class); $lines = [['object' => $this->product, 'qty' => 3]];
        try { $pricing->quote($lines, 'CUMUL'); self::fail('Cumul interdit'); } catch (\DomainException $e) { self::assertSame(CommercialPricing::INVALID_CODE, $e->getMessage()); }
        $code->setStackable(true); $this->em->flush(); self::assertSame(4860, $pricing->quote($lines, 'CUMUL')['totalCents']);
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $address, $carrier, $lines, null, 'CUMUL');
        self::assertSame(5360, $order->getTotalCents()); self::assertSame(893, (int) round($order->getTotalTva() * 100));
        $this->product->setPromotionPercent(50); $code->setValue(90); $this->em->flush();
        self::assertSame(5360, $order->getTotalCents()); self::assertSame(18.0, $order->getOrderDetails()->first()->getProductPrice());
    }
}
