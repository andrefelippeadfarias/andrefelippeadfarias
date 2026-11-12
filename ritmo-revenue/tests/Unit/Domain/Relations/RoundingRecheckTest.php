<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\RecheckFinding;
use Ritmo\Domain\Relations\RecheckMode;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RoundingRecheck;

/**
 * The examples of the specification, on the bases of the sample (LOW 1173, HIGH 1480, MID 1590,
 * TOP 2000): MID between LOW and HIGH, TOP at least 15% above HIGH.
 */
final class RoundingRecheckTest extends TestCase
{
    private static function recheck(RecheckMode $mode): array
    {
        $relations = [
            RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75]),
            RelationsSample::relation('top', 'at_least', 'TOP', ['reference' => 'HIGH', 'margin' => ['type' => 'percent', 'value' => 15]]),
            ...RelationsSample::lodgeRelations(),
        ];
        $graph = new RelationGraph($relations, RelationsSample::rooms());

        return [new RoundingRecheck($graph, new RelationSettings(recheckMode: $mode)), $graph->ordered()];
    }

    public function testATargetOneStepAboveItsHighReferenceIsLoweredOneStep(): void
    {
        [$recheck, $relations] = self::recheck(RecheckMode::Fix);
        $result = $recheck->check($relations, NightType::Weekday, [
            'LOW' => ['floor' => -6, 'ceiling' => null], 'HIGH' => ['floor' => 2, 'ceiling' => null], 'MID' => ['floor' => -5, 'ceiling' => -5],
        ], []);

        self::assertSame(-6, $result['percents']['MID']['floor'], '1510.50 above 1509.60: one step lower is 1494.60');
        self::assertSame(-6, $result['percents']['MID']['ceiling']);
        self::assertSame([RecheckFinding::INVERSION, 1510.5, 1509.6, -5, -6], self::finding($result['findings'][0]));
    }

    public function testReferencesCloserThanOneStepCannotBeSolved(): void
    {
        [$recheck, $relations] = self::recheck(RecheckMode::Fix);
        $result = $recheck->check($relations, NightType::Weekday, [
            'LOW' => ['floor' => 28, 'ceiling' => null], 'HIGH' => ['floor' => 2, 'ceiling' => null], 'MID' => ['floor' => -5, 'ceiling' => null],
        ], []);

        self::assertSame(-5, $result['percents']['MID']['floor'], 'one step lower (1494.60) would be below LOW (1501.44)');
        self::assertSame(RecheckFinding::UNSOLVABLE, $result['findings'][0]->code);
    }

    public function testATargetBelowWhatItsReferenceRequiresIsRaised(): void
    {
        [$recheck, $relations] = self::recheck(RecheckMode::Fix);
        $result = $recheck->check($relations, NightType::Weekday, ['HIGH' => ['floor' => 2, 'ceiling' => null], 'TOP' => ['floor' => -14, 'ceiling' => -14]], ['LOW' => 1100.0]);

        self::assertSame(['floor' => -13, 'ceiling' => -13], $result['percents']['TOP'], '1720 below 1.15 x 1509.60 = 1736.04: 1740');
        self::assertSame(RecheckFinding::BELOW_REQUIRED, $result['findings'][0]->code);
    }

    public function testAFixNeverGoesBelowTheTargetsLowestPercent(): void
    {
        [$recheck, $relations] = self::recheck(RecheckMode::Fix);
        $result = $recheck->check($relations, NightType::Weekday, [
            'LOW' => ['floor' => -6, 'ceiling' => null], 'HIGH' => ['floor' => 2, 'ceiling' => null], 'MID' => ['floor' => -5, 'ceiling' => null, 'lowest' => -5],
        ], []);

        self::assertSame(-5, $result['percents']['MID']['floor']);
        self::assertSame(RecheckFinding::UNSOLVABLE, $result['findings'][0]->code);
    }

    public function testWarnReportsWithoutChangingAndOffSaysNothing(): void
    {
        $percents = ['LOW' => ['floor' => -6, 'ceiling' => null], 'HIGH' => ['floor' => 2, 'ceiling' => null], 'MID' => ['floor' => -5, 'ceiling' => null], 'PLAIN' => ['floor' => -18, 'ceiling' => -18]];
        [$warn, $relations] = self::recheck(RecheckMode::Warn);
        $result = $warn->check($relations, NightType::Weekday, $percents, ['SPA' => 1000.0]);
        [$off] = self::recheck(RecheckMode::Off);

        self::assertSame($percents, $result['percents']);
        self::assertSame([RecheckFinding::INVERSION, 1510.5, 1509.6, -5, null], self::finding($result['findings'][0]));
        self::assertCount(1, $result['findings'], 'the band and the switch are exempt');
        self::assertSame([], $off->check($relations, NightType::Weekday, $percents, [])['findings']);
    }

    /**
     * @return array{string, float, float, int, int|null}
     */
    private static function finding(RecheckFinding $finding): array
    {
        return [$finding->code, $finding->effective, $finding->bound, $finding->fromPercent, $finding->toPercent];
    }
}
