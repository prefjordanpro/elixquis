<?php
declare(strict_types=1);
namespace App\DataFixtures;

use App\Entity\Revendeur;
use Doctrine\Bundle\FixturesBundle\{Fixture, FixtureGroupInterface};
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Deliberately fictional records. Never registered as services in production. */
final class RevendeurDemoFixtures extends Fixture implements FixtureGroupInterface
{
    public const ADDRESS = 'Adresse de démonstration (fictive)';
    public const DISCLAIMER = 'Commerce entièrement fictif : aucun partenariat réel avec Elixquis.';
    public const DOMAIN = 'revendeurs-demo.invalid';
    public const STORES = [
        ['paris', 'La Cave des Quais', '75001', 'Paris', 48.8566, 2.3522, true],
        ['lille', 'Maison des Saveurs', '59000', 'Lille', 50.6292, 3.0573, true],
        ['nantes', 'Le Comptoir Tropical', '44000', 'Nantes', 47.2184, -1.5536, true],
        ['bordeaux', 'L’Atelier des Spiritueux', '33000', 'Bordeaux', 44.8378, -0.5792, true],
        ['marseille', 'La Cave du Vieux-Port', '13001', 'Marseille', 43.2965, 5.3698, true],
        ['lyon', 'Le Comptoir Lyonnais', '69001', 'Lyon', 45.7640, 4.8357, true],
        ['strasbourg', 'Les Saveurs d’Alsace', '67000', 'Strasbourg', 48.5734, 7.7521, true],
        ['toulouse', 'La Cave du Capitole', '31000', 'Toulouse', 43.6047, 1.4442, true],
        ['inactif', 'Le Comptoir Masqué', '35000', 'Rennes', 48.1173, -1.6778, false],
    ];

    public function __construct(#[Autowire('%kernel.environment%')] private string $environment) {}

    public static function getGroups(): array { return ['revendeurs-demo']; }

    public function assertAllowed(): void
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw new \LogicException('Les revendeurs fictifs sont réservés aux environnements dev et test.');
        }
    }

    public function load(ObjectManager $manager): void
    {
        $this->assertAllowed();
        foreach (self::STORES as $position => [$key, $name, $postcode, $city, $lat, $lng, $active]) {
            $email = $key.'@'.self::DOMAIN;
            $retailer = $manager->getRepository(Revendeur::class)->findOneBy([
                'email' => $email, 'adresse' => self::ADDRESS, 'complementAdresse' => self::DISCLAIMER,
            ]) ?? new Revendeur();
            $retailer->setNom($name.' — FICTIF (démonstration)')
                ->setAdresse(self::ADDRESS)->setComplementAdresse(self::DISCLAIMER)
                ->setCodePostal($postcode)->setVille($city)->setPays('France')
                ->setLatitude($lat)->setLongitude($lng)->setActif($active)->setPriorite($position)
                ->setEmail($email)
                ->setTelephone($position % 2 === 0 ? '00 00 00 00 00 (fictif)' : null)
                ->setSiteWeb($position % 3 === 0 ? 'https://'.$key.'.'.self::DOMAIN : null);
            $manager->persist($retailer);
        }
        $manager->flush();
    }

    /** Only remove records carrying all three fixture identifiers; preserve other data. */
    public function remove(ObjectManager $manager): int
    {
        $this->assertAllowed();
        $count = 0;
        foreach (self::STORES as [$key]) {
            foreach ($manager->getRepository(Revendeur::class)->findBy([
                'email' => $key.'@'.self::DOMAIN, 'adresse' => self::ADDRESS, 'complementAdresse' => self::DISCLAIMER,
            ]) as $retailer) {
                $manager->remove($retailer); ++$count;
            }
        }
        $manager->flush();
        return $count;
    }
}
