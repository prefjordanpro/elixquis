<?php
declare(strict_types=1);
namespace App\Tests;
use App\Entity\{Emballage, Product};
use App\Repository\EmballageRepository;
use App\Service\{Colisage, ShippingConfiguration};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ColisageTest extends TestCase
{
    private function service(): Colisage
    {
        return new Colisage($this->createMock(EmballageRepository::class), new ShippingConfiguration(false, [], [], 20, false));
    }
    private function box(int $capacity, int $tare = 200): Emballage
    {
        // Valeurs strictement automatisées : aucune mesure de carton de production.
        return (new Emballage())->setNom('Carton test '.$capacity)->setCapacite($capacity)->setPoidsVideGrammes($tare)
            ->setLongueurCm(32)->setLargeurCm(22)->setHauteurCm(41)->setActif(true);
    }
    private function lines(int $quantity, ?int $weight = 1200, string $name = 'Bouteille test'): array
    {
        return [['object' => (new Product())->setName($name)->setShippingWeightGrams($weight), 'qty' => $quantity]];
    }
    public static function quantities(): iterable
    {
        foreach ([1 => [1], 2 => [2], 3 => [3], 4 => [3, 1], 5 => [3, 2], 6 => [6], 7 => [6, 1],
            8 => [6, 2], 9 => [6, 3], 10 => [6, 3, 1], 11 => [6, 3, 2], 12 => [6, 6]] as $qty => $expected) { yield [$qty, $expected]; }
    }
    /** @dataProvider quantities */
    public function testRequestedPackingAndExactWeight(int $qty, array $expected): void
    {
        $boxes = array_map(fn ($n) => $this->box($n), [1, 2, 3, 6]);
        $plan = $this->service()->calculer($this->lines($qty), $boxes);
        self::assertSame($expected, array_column(array_column($plan, 'emballage'), 'capacite'));
        self::assertSame($qty, array_sum(array_column($plan, 'nombre_unites')));
        self::assertSame($qty * 1200 + count($plan) * 200, array_sum(array_column($plan, 'poids_total_g')));
        self::assertSame($plan, $this->service()->calculer($this->lines($qty), array_reverse($boxes)));
    }
    public function testDifferentWeightsAndActualFutureProductWeight(): void
    {
        $lines = [...$this->lines(1, 1200, 'A'), ...$this->lines(1, 1350, 'B')];
        $plan = $this->service()->calculer($lines, [$this->box(2, 300)]);
        self::assertSame(2550, $plan[0]['poids_produits_g']); self::assertSame(2850, $plan[0]['poids_total_g']);
        self::assertCount(2, $plan[0]['contenu']);
        self::assertSame('2.850', $this->service()->parcels($plan)[0]['weight']['value']);
    }
    public function testWeightLimitExploresMixedReferencesRatherThanContiguousHeavyItems(): void
    {
        $box = $this->box(2, 100)->setPoidsMaxGrammes(2100);
        $plan = $this->service()->calculer([...$this->lines(2, 1500, 'Lourde'), ...$this->lines(2, 500, 'Légère')], [$box]);
        self::assertCount(2, $plan);
        foreach ($plan as $parcel) { self::assertSame(2100, $parcel['poids_total_g']); self::assertCount(2, $parcel['contenu']); }
    }
    public function testInactiveAndIncompleteBoxesAreExcluded(): void
    {
        $plan = $this->service()->calculer($this->lines(2), [$this->box(2)->setActif(false), $this->box(2)->setPoidsVideGrammes(null), $this->box(1)]);
        self::assertCount(2, $plan); self::assertSame([1, 1], array_column($plan, 'nombre_unites'));
    }
    public function testArbitraryFormatAndPartialFillWhenNoExactCombinationExists(): void
    {
        $plan = $this->service()->calculer($this->lines(7), [$this->box(4)]);
        self::assertCount(2, $plan); self::assertSame(7, array_sum(array_column($plan, 'nombre_unites')));
        self::assertSame(4, $plan[0]['emballage']['capacite']);
    }
    public function testNonGreedyCombinationMinimizesParcelCount(): void
    {
        $plan = $this->service()->calculer($this->lines(8), [$this->box(1), $this->box(4), $this->box(6)]);
        self::assertSame([4, 4], array_column($plan, 'nombre_unites'));
    }
    public function testPriorityAtSameCapacity(): void
    {
        $first = $this->box(2)->setPriorite(5); $second = $this->box(2, 300)->setPriorite(1);
        self::assertSame(300, $this->service()->calculer($this->lines(2), [$first, $second])[0]['emballage']['poids_emballage_g']);
    }
    public function testMissingProductWeightIsRejected(): void
    {
        $this->expectException(\DomainException::class); $this->service()->calculer($this->lines(1, null), [$this->box(1)]);
    }
    public function testExceededMaximumWeightIsRejected(): void
    {
        $this->expectException(\DomainException::class); $this->service()->calculer($this->lines(1), [$this->box(1)->setPoidsMaxGrammes(1300)]);
    }
    public function testNoCompatibleBoxIsRejected(): void
    {
        $this->expectException(\DomainException::class); $this->service()->calculer($this->lines(1), [$this->box(1)->setActif(false)]);
    }
    public function testDraftCanBeSavedButCannotBeActivated(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $box = $this->box(2)->setPoidsVideGrammes(null)->setActif(false);
        self::assertCount(0, $validator->validate($box));
        $box->setActif(true); self::assertGreaterThan(0, count($validator->validate($box)));
    }
    public function testConfiguredInactiveBoxesCannotFallBackToEnvironment(): void
    {
        $repository = $this->createMock(EmballageRepository::class); $repository->method('findBy')->willReturn([$this->box(2)->setActif(false)]);
        $this->expectException(\DomainException::class);
        (new Colisage($repository, new ShippingConfiguration(false, [], [], 20, false)))->plan($this->lines(2));
    }
}
