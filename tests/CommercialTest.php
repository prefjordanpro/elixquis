<?php
namespace App\Tests;

use App\Entity\{Address, Carrier, CommercialSettings, HomepageContent, Order, Product, PromoCode, User};
use App\Service\{CommercialPricing, OrderManager, StripePayment};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** SQLite mémoire uniquement ; Stripe mocké, aucun appel transporteur/email. */
class CommercialTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private Product $product;
    private User $user;
    private Address $address;
    private Carrier $carrier;
    private CommercialSettings $settings;
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new User())->setEmail('commercial@example.test')->setFirstname('Test')->setLastname('Commerce')->setPassword('hash')->setRoles(['ROLE_ADMIN']);
        $this->address = (new Address())->setUser($this->user)->setFirstname('Test')->setLastname('Commerce')->setAddress('1 rue de Test')->setPostal('75001')->setCity('Paris')->setCountry('FR')->setPhone('0612345678');
        $this->carrier = (new Carrier())->setName('Transporteur réel')->setDescription('Domicile')->setPrice(5)->setTva(20);
        $this->product = (new Product())->setName('Recette commerciale')->setSlug('commerce')->setDescription('Recette de test')->setIllustration('2025-08-30-851cfa871ccd52de3f20d860b2188b7f05abebe4.jpg')->setPrice(20)->setTva(20)->setStock(100)->setIsHomepage(true);
        $this->settings = new CommercialSettings();
        foreach ([$this->user, $this->address, $this->carrier, $this->product, $this->settings] as $entity) { $this->em->persist($entity); }
        foreach (HomepageContent::DEFAULTS as $key => $value) { $this->em->persist(new HomepageContent($key)); }
        $this->em->flush();
    }
    private function pricing(): CommercialPricing { return static::getContainer()->get(CommercialPricing::class); }
    private function export(string $name): void
    {
        if ($dir = getenv('UX_EXPORT_DIR')) { if (!is_dir($dir)) { mkdir($dir, 0777, true); } file_put_contents($dir.'/'.$name.'.html', $this->client->getResponse()->getContent()); }
    }
    private function lines(int $quantity = 1): array { return [['object' => $this->product, 'qty' => $quantity]]; }
    private function coupon(string $type = 'percent', int $value = 10): PromoCode
    {
        $coupon = (new PromoCode())->setCode('TEST')->setName('Test')->setActive(true)->setType($type)->setValue($value);
        $this->em->persist($coupon); $this->em->flush(); return $coupon;
    }
    private function order(int $quantity = 1, ?string $code = null, ?\App\Shipping\DeliverySelection $delivery = null): Order
    {
        return static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier, $this->lines($quantity), $delivery, $code);
    }
    public function testStockThresholds(): void
    {
        self::assertSame('Rupture', $this->pricing()->stockStatus(0));
        self::assertSame('Stock faible', $this->pricing()->stockStatus(5));
        self::assertSame('Disponible', $this->pricing()->stockStatus(6));
        $this->settings->setLowStockThreshold(9); self::assertSame('Stock faible', $this->pricing()->stockStatus(6));
    }
    /** @dataProvider promotionDates */
    public function testDatedProductPromotions(bool $enabled, ?string $start, ?string $end, int $expected): void
    {
        $this->product->setPromotionEnabled($enabled)->setPromotionPriceCents(1800)
            ->setPromotionStartsAt($start ? new \DateTimeImmutable($start) : null)->setPromotionEndsAt($end ? new \DateTimeImmutable($end) : null);
        self::assertSame($expected, (int) round($this->product->getPriceWt() * 100));
    }
    public static function promotionDates(): array
    {
        return [[false, null, null, 2400], [true, null, null, 1800], [true, '+1 day', '+2 days', 2400], [true, '-2 days', '-1 day', 2400], [true, '-1 day', '+1 day', 1800]];
    }
    /** @dataProvider discounts */
    public function testCodeAmounts(string $type, int $value, int $expected): void
    {
        $this->coupon($type, $value);
        self::assertSame($expected, $this->pricing()->quote($this->lines(), 'test')['discountCents']);
        $order = $this->order(1, 'TEST');
        self::assertSame(2400 - $expected + 500, $order->getTotalCents());
        self::assertSame($expected, $order->getPromoDiscountCents());
        self::assertSame('TEST', $order->getPromoCode());
    }
    public static function discounts(): array { return [['percent', 10, 240], ['fixed', 501, 501], ['fixed', 90000, 2400], ['percent', 100, 2400]]; }
    /** @dataProvider invalidCodes */
    public function testInapplicableCodes(string $reason): void
    {
        $coupon = $this->coupon();
        match ($reason) {
            'expired' => $coupon->setEndsAt(new \DateTimeImmutable('-1 day')),
            'future' => $coupon->setStartsAt(new \DateTimeImmutable('+1 day')),
            'minimum' => $coupon->setMinimumCents(2401),
            'limit' => $coupon->setUsageLimit(1)->recordConfirmedUse(),
            'inactive' => $coupon->setActive(false),
        };
        $this->expectException(\DomainException::class); $this->expectExceptionMessage(CommercialPricing::INVALID_CODE);
        $this->pricing()->quote($this->lines(), 'TEST');
    }
    public static function invalidCodes(): array { return [['expired'], ['future'], ['minimum'], ['limit'], ['inactive']]; }
    public function testStackingAndExcludedPromotedProducts(): void
    {
        $this->product->setPromotionEnabled(true)->setPromotionPriceCents(1800);
        $coupon = $this->coupon();
        try { $this->pricing()->quote($this->lines(), 'TEST'); self::fail('Cumul interdit'); } catch (\DomainException $e) { self::assertSame(CommercialPricing::INVALID_CODE, $e->getMessage()); }
        $coupon->setStackable(true); self::assertSame(1620, $this->pricing()->quote($this->lines(), 'TEST')['totalCents']);
        $coupon->setStackable(false);
        $normal = clone $this->product; $normal->setPromotionEnabled(false);
        $quote = $this->pricing()->quote([['object' => $this->product, 'qty' => 1], ['object' => $normal, 'qty' => 1]], 'TEST');
        self::assertSame(240, $quote['discountCents']); self::assertSame([0, 240], $quote['discounts']);
    }
    /** @dataProvider shippingRules */
    public function testFreeShipping(string $mode, int $threshold, int $shipping): void
    {
        $this->settings->setFreeShippingMode($mode)->setFreeShippingThresholdCents($threshold); $this->em->flush();
        $selection = new \App\Shipping\DeliverySelection(['carrier_name' => 'Sendcloud réel', 'method_name' => 'Express', 'items' => [['id' => $this->product->getId(), 'qty' => 1, 'weight_grams' => null, 'price_cents' => 2400]], 'quoted_price_cents' => 799], 799, 20);
        $order = $this->order(1, null, $selection);
        self::assertSame($shipping, (int) round($order->getCarrierPrice() * 100));
        self::assertSame(799, $order->getTransportCostCents());
        self::assertSame($selection->snapshot, $order->getShippingSnapshot());
        self::assertSame('Sendcloud réel', $order->getCarrierName());
    }
    public static function shippingRules(): array { return [['disabled', 0, 799], ['all', 0, 0], ['threshold', 2400, 0], ['threshold', 2401, 799]]; }
    public function testShippingThresholdUsesArticlesAfterCode(): void
    {
        $this->settings->setFreeShippingMode('threshold')->setFreeShippingThresholdCents(2400);
        $this->coupon(); $this->em->flush();
        self::assertFalse($this->pricing()->quote($this->lines(), 'TEST')['freeShipping']);
    }
    public function testSnapshotAndTaxSurviveChangesAndStockRestoresNormally(): void
    {
        $this->product->setPromotionEnabled(true)->setPromotionPriceCents(1800);
        $coupon = $this->coupon('fixed', 101)->setStackable(true); $this->em->flush();
        $order = $this->order(3, 'TEST');
        $total = $order->getTotalCents(); $tax = $order->getTotalTva();
        self::assertSame(5799, $total); self::assertCount(1, $order->getOrderDetails());
        self::assertSame(5299, $order->getOrderDetails()->first()->getTotalPriceCents());
        $this->product->setPromotionEnabled(false)->setPrice(99); $coupon->setValue(999)->setCode('MODIFIED'); $this->em->flush();
        $this->em->clear(); $order = $this->em->find(Order::class, $order->getId());
        self::assertSame($total, $order->getTotalCents()); self::assertSame($tax, $order->getTotalTva()); self::assertSame('TEST', $order->getPromoCode());
        static::getContainer()->get(OrderManager::class)->cancelUnpaid($order);
        self::assertSame(100, $this->em->find(Product::class, $this->product->getId())->getStock());
    }
    public function testUsageLimitIsCountedOnlyAfterPaymentConfirmation(): void
    {
        $coupon = $this->coupon()->setUsageLimit(1); $this->em->flush();
        $order = $this->order(1, 'TEST'); self::assertSame(0, $coupon->getUses());
        $order->setStripeSessionId('cs_usage_confirmed'); $this->em->flush();
        static::getContainer()->get(OrderManager::class)->confirmPayment($order, (object) [
            'id' => 'cs_usage_confirmed', 'payment_status' => 'paid', 'currency' => 'eur',
            'amount_total' => $order->getTotalCents(), 'client_reference_id' => (string) $order->getId(),
        ]);
        self::assertSame(1, $coupon->getUses());
        $this->expectException(\DomainException::class); $this->order(1, 'TEST');
    }
    public function testCartUsesPromotionAndCodeWithCsrf(): void
    {
        $this->product->setPromotionEnabled(true)->setPromotionPriceCents(1800); $this->coupon()->setStackable(true); $this->em->flush();
        $page = $this->client->request('GET', '/produit/commerce'); self::assertSelectorTextContains('del', '24,00');
        $this->client->submit($page->selectButton('Ajouter au panier')->form()); $this->client->followRedirect();
        self::assertSelectorTextContains('.summary-total', '18,00');
        $this->client->submitForm('Appliquer', ['code' => 'test']); $this->client->followRedirect();
        self::assertSelectorTextContains('.summary-total', '16,20');
        $this->export('commercial-panier');
        $this->client->submitForm('Retirer le code'); $this->client->followRedirect(); self::assertSelectorTextContains('.summary-total', '18,00');
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/cart/promo', ['code' => 'TEST']); self::assertResponseStatusCodeSame(403);
    }
    public function testStripeRequestUsesExactDiscountedCents(): void
    {
        $this->product->setPromotionPercent(25); $this->settings->setFreeShippingMode('all');
        $this->coupon('fixed', 101)->setStackable(true); $this->em->flush(); $order = $this->order(3, 'TEST');
        $previous = $_ENV['STRIPE_SECRET_KEY'] ?? null; $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_mock_commercial';
        $http = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url, $headers, $params) {
            self::assertSame('post', $method); self::assertCount(2, $params['line_items']);
            $total = array_sum(array_map(fn ($line) => $line['price_data']['unit_amount'] * $line['quantity'], $params['line_items']));
            self::assertSame(5299, $total);
            return [json_encode(['id' => 'cs_commercial_mock', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/mock']), 200, []];
        });
        \Stripe\ApiRequestor::setHttpClient($http);
        try { self::assertSame('https://checkout.stripe.test/mock', static::getContainer()->get(StripePayment::class)->checkout($order)); }
        finally { \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance()); if ($previous === null) { unset($_ENV['STRIPE_SECRET_KEY']); } else { $_ENV['STRIPE_SECRET_KEY'] = $previous; } }
    }
    public function testAdminDashboardAndHomepageTextEditing(): void
    {
        $this->order();
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/admin'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('.content-body', 'Stocks');
        $this->export('commercial-dashboard');
        $this->client->click($page->selectLink('Accueil du site')->link()); self::assertResponseIsSuccessful();
        $page = $this->client->request('GET', '/admin/homepage-content/hero_title/edit'); self::assertResponseIsSuccessful();
        $form = $page->filter('form[name="HomepageContent"]')->form(['HomepageContent[value]' => 'Titre administré']);
        $this->client->submit($form); self::assertResponseRedirects();
        $this->client->request('GET', '/'); self::assertSelectorTextContains('h1', 'Titre administré');
    }
    public function testAdminConfigurationAndCodesPages(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/commercial-settings');
        self::assertResponseRedirects('/admin/commercial-settings/1/edit');
        foreach (['/admin/commercial-settings/1/edit', '/admin/promo-code/new', '/admin/homepage-content', '/admin/product/'.$this->product->getId().'/edit'] as $url) {
            $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        }
    }
    public function testAdminCanCreateCodeWithMoneyMinimum(): void
    {
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/admin/promo-code/new');
        $form = $page->filter('form[name="PromoCode"]')->form(['PromoCode[code]' => ' admin500 ', 'PromoCode[name]' => 'Test admin',
            'PromoCode[type]' => 'fixed', 'PromoCode[value]' => '5,00', 'PromoCode[minimumCents]' => '12', 'PromoCode[usageLimit]' => '2', 'PromoCode[active]' => true]);
        $this->client->submit($form); self::assertResponseRedirects();
        $code = $this->em->getRepository(PromoCode::class)->findOneBy(['code' => 'ADMIN500']);
        self::assertNotNull($code); self::assertSame(500, $code->getValue()); self::assertSame(1200, $code->getMinimumCents());
        self::assertFalse($code->isStackable());
        $this->client->followRedirect(); self::assertResponseIsSuccessful(); $this->export('commercial-codes');
    }
    public function testCommercialConfigurationFormAndGlobalDashboardThreshold(): void
    {
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/admin');
        $this->client->click($page->selectLink('Configuration commerciale')->link());
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('.datagrid');
        self::assertSelectorTextContains('.content-header', 'Configuration commerciale');
        self::assertSelectorTextContains('form[name="CommercialSettings"]', 'STOCKS'); self::assertSelectorTextContains('form[name="CommercialSettings"]', 'LIVRAISON');
        self::assertSelectorExists('[data-commercial-shipping-mode]');
        self::assertSelectorExists('[data-commercial-shipping-threshold]');
        $this->export('commercial-configuration');
        $form = $this->client->getCrawler()->selectButton('Enregistrer la configuration')->form([
            'CommercialSettings[lowStockThreshold]' => '100', 'CommercialSettings[freeShippingMode]' => 'threshold',
            'CommercialSettings[freeShippingThresholdCents]' => '100',
        ]);
        $this->client->submit($form); self::assertResponseRedirects('/admin/commercial-settings/1/edit');
        $this->client->followRedirect(); self::assertResponseIsSuccessful();
        $settings = $this->em->find(CommercialSettings::class, 1);
        self::assertSame(100, $settings->getLowStockThreshold()); self::assertSame(10000, $settings->getFreeShippingThresholdCents());
        $this->export('commercial-configuration-seuil');
        $this->client->request('GET', '/admin'); self::assertSelectorTextContains('.content-body', 'Stocks faibles · 1');
        self::assertSelectorTextContains('.content-body', 'Stock faible : de 1 à 100 unités.');
    }
    public function testMultiRateOrderDiscountKeepsTotalsAndTaxesConsistent(): void
    {
        $second = (new Product())->setName('Autre taux')->setSlug('autre-taux')->setDescription('Test')->setIllustration('test.jpg')->setPrice(10)->setTva(5.5)->setStock(10);
        $this->em->persist($second); $this->coupon('fixed', 101); $this->em->flush();
        $order = static::getContainer()->get(OrderManager::class)->create($this->user, $this->address, $this->carrier,
            [['object' => $this->product, 'qty' => 3], ['object' => $second, 'qty' => 2]], null, 'TEST');
        $net = $discount = $tax = 0;
        foreach ($order->getOrderDetails() as $line) {
            $net += $line->getTotalPriceCents(); $discount += $line->getCommercialDiscountCents(); $tax += $line->getTotalTvaCents();
            self::assertGreaterThanOrEqual(0, $line->getTotalPriceCents());
            self::assertSame($line->getTotalPriceCents() - (int) round($line->getTotalPriceCents() / (1 + $line->getProductTva() / 100)), $line->getTotalTvaCents());
        }
        self::assertSame(101, $discount); self::assertSame(9209, $net); self::assertSame(9709, $order->getTotalCents());
        self::assertSame(($tax + 83) / 100, $order->getTotalTva());
    }
    public function testCommercialServerValidation(): void
    {
        $validator = static::getContainer()->get('validator');
        $this->settings->setLowStockThreshold(0)->setFreeShippingMode('invented')->setFreeShippingThresholdCents(-1);
        self::assertCount(3, $validator->validate($this->settings));
        $this->product->setPromotionEnabled(true)->setPromotionPriceCents(2500)->setPromotionStartsAt(new \DateTimeImmutable('+2 days'))->setPromotionEndsAt(new \DateTimeImmutable('+1 day'));
        self::assertCount(2, $validator->validate($this->product));
        $code = (new PromoCode())->setCode('BAD SPACE')->setName('Test')->setValue(101)->setStartsAt(new \DateTimeImmutable('+2 days'))->setEndsAt(new \DateTimeImmutable('+1 day'));
        self::assertCount(3, $validator->validate($code));
        $this->coupon(); self::assertGreaterThan(0, count($validator->validate((new PromoCode())->setCode('test')->setName('Doublon'))));
    }
    public function testHomepageImageUploadAndPreview(): void
    {
        $this->client->loginUser($this->user);
        $original = dirname(__DIR__).'/public/images/editorial/degustation-ambree-v2.jpg';
        $temporary = tempnam(sys_get_temp_dir(), 'elixquis-home-'); copy($original, $temporary);
        $uploaded = null;
        try {
            $page = $this->client->request('GET', '/admin/homepage-content/hero_image/edit'); self::assertResponseIsSuccessful();
            self::assertSelectorExists('img[alt="Image actuelle"]');
            $form = $page->filter('form[name="HomepageContent"]')->form(); $form['HomepageContent[value][file]']->upload($temporary, 'photo.jpg');
            $this->client->submit($form); self::assertResponseRedirects();
            $entry = $this->em->find(HomepageContent::class, 'hero_image');
            self::assertMatchesRegularExpression('/^[a-f0-9]+\.(jpg|jpeg)$/', $entry->getValue());
            $uploaded = dirname(__DIR__).'/public/'.$entry->getImagePath(); self::assertFileExists($uploaded); self::assertFileExists($original);
            $this->client->request('GET', '/'); self::assertSelectorExists('.hero-image > img[src="/'.$entry->getImagePath().'"]');
        } finally { if (is_file($temporary)) { unlink($temporary); } if ($uploaded && is_file($uploaded)) { unlink($uploaded); } }
    }
    public function testAdminEndpointsAreProtected(): void
    {
        $this->user->setRoles(['ROLE_USER']); $this->em->flush(); $this->client->loginUser($this->user);
        foreach (['/admin', '/admin/homepage-content', '/admin/promo-code', '/admin/commercial-settings'] as $url) { $this->client->request('GET', $url); self::assertResponseStatusCodeSame(403); }
    }
    public function testHomepageRejectsNonImageUpload(): void
    {
        $this->client->loginUser($this->user);
        $temporary = tempnam(sys_get_temp_dir(), 'elixquis-invalid-'); file_put_contents($temporary, '<?php echo "invalid";');
        try {
            $page = $this->client->request('GET', '/admin/homepage-content/hero_image/edit');
            $form = $page->filter('form[name="HomepageContent"]')->form(); $form['HomepageContent[value][file]']->upload($temporary, 'pretend.jpg');
            $this->client->submit($form);
            self::assertFalse($this->client->getResponse()->isRedirection());
            self::assertSelectorExists('.invalid-feedback');
            self::assertFileDoesNotExist(dirname(__DIR__).'/public/'.$this->em->find(HomepageContent::class, 'hero_image')->getImagePath());
            $this->em->clear();
            self::assertSame(HomepageContent::DEFAULTS['hero_image'][2], $this->em->find(HomepageContent::class, 'hero_image')->getValue());
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}
