<?php
declare(strict_types=1);

namespace App\Tests;

use App\Command\ColisageDebugCommand;
use App\Entity\{Emballage, Product};
use App\Repository\{EmballageRepository, ProductRepository};
use App\Service\{Colisage, ShippingConfiguration};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ColisageDebugCommandTest extends TestCase
{
    private function command(string $environment, bool $read = true): CommandTester
    {
        $boxes = $this->createMock(EmballageRepository::class);
        $boxes->expects($read ? self::once() : self::never())->method('findBy')->willReturn([
            (new Emballage())->setNom('Carton 6')->setCapacite(6)->setPoidsVideGrammes(1200)
                ->setLongueurCm(32)->setLargeurCm(22)->setHauteurCm(41)->setActif(true),
            (new Emballage())->setNom('Carton 1')->setCapacite(1)->setPoidsVideGrammes(300)
                ->setLongueurCm(12)->setLargeurCm(12)->setHauteurCm(38.5)->setActif(true),
        ]);
        $products = $this->createMock(ProductRepository::class);
        $products->expects($read ? self::once() : self::never())->method('find')->with(4)
            ->willReturn((new Product())->setName('Bouteille test')->setShippingWeightGrams(1200));
        return new CommandTester(new ColisageDebugCommand(
            new Colisage($boxes, new ShippingConfiguration(false, [], [], 20, false)), $products, $environment,
        ));
    }

    public function testSevenBottlesUsesActualPlanAndDisplaysWeightsAndDimensions(): void
    {
        $command = $this->command('dev');
        self::assertSame(0, $command->execute(['--product' => '4', '--quantity' => '7']));
        foreach (['Carton 6', 'Carton 1', '7200 g', '1200 g', '8400 g', '300 g', '1500 g',
            '32 × 22 × 41 cm', '12 × 12 × 38.5 cm', 'Bouteille test', '2 colis / 9900 g'] as $text) {
            self::assertStringContainsString($text, $command->getDisplay());
        }
    }

    public function testRepeatedBasketLinesAreCombined(): void
    {
        $command = $this->command('dev');
        self::assertSame(0, $command->execute(['--line' => ['4:3', '4:4']]));
        self::assertStringContainsString('2 colis / 9900 g', $command->getDisplay());
    }

    public function testProductionRefusesBeforeReadingRepositories(): void
    {
        self::assertSame(1, $this->command('prod', false)->execute(['--product' => '4', '--quantity' => '7']));
    }

    public function testInvalidQuantityIsRejectedBeforeReadingRepositories(): void
    {
        self::assertSame(1, $this->command('dev', false)->execute(['--product' => '4', '--quantity' => '0']));
    }
}
