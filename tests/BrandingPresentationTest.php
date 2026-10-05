<?php

namespace App\Tests;

use App\Entity\{Header, Product};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Cas complémentaires : carrousel administré et produit sans description. */
final class BrandingPresentationTest extends WebTestCase
{
    public function testCarouselAndUnavailableProductKeepTheirControls(): void
    {
        $client = static::createClient(); $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $em->getConnection()->getParams()['driver']);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        foreach (['Première image de test', 'Deuxième image de test'] as $title) {
            $em->persist((new Header())->setTitle($title)->setContent('Contenu administré de démonstration.')
                ->setIllustration('2025-08-30-851cfa871ccd52de3f20d860b2188b7f05abebe4.jpg')
                ->setButtonTitle('Découvrir')->setButtonLink('/catalogue'));
        }
        $product = (new Product())->setName('Litchi de démonstration')->setSlug('litchi-demo')->setDescription('')
            ->setIllustration('2025-08-30-851cfa871ccd52de3f20d860b2188b7f05abebe4.jpg')
            ->setPrice(20)->setTva(20)->setStock(0)->setIsHomepage(true);
        $em->persist($product); $em->flush();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#homeCarousel .carousel-item'));
        self::assertCount(2, $crawler->filter('#homeCarousel .carousel-indicators button'));
        self::assertSelectorExists('#homeCarousel [data-bs-slide-to="1"]');
        $this->export($client, 'accueil-carrousel');
        $client->request('GET', '/produit/litchi-demo');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.product-description');
        self::assertSelectorExists('button[type="submit"][disabled]');
        self::assertSelectorTextContains('.availability', 'Rupture de stock');
        self::assertSelectorExists('.product-back-link[href="/catalogue"]');
        $this->export($client, 'produit-indisponible');
    }

    private function export($client, string $name): void
    {
        if ($directory = getenv('UX_EXPORT_DIR')) {
            if (!is_dir($directory)) { mkdir($directory, 0777, true); }
            file_put_contents($directory.'/'.$name.'.html', $client->getResponse()->getContent());
        }
    }
}
