<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Support;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Support\Num;
use Ritmo\Tests\Support\Golden;

/**
 * Num against the golden fixtures of the reference engine: integers and percentages must match
 * exactly.
 */
final class NumGoldenTest extends TestCase
{
    public function testRoundHalfEvenMatchesPythonRound(): void
    {
        $this->assertGolden(
            'builtins.round',
            static fn (array $in): float => Num::roundHalfEven((float) $in['value'], $in['decimals'])
        );
    }

    public function testFloorToPercentMatchesPisoPct(): void
    {
        $this->assertGolden(
            'validar_plano.piso_pct',
            static fn (array $in): int => Num::floorToPercent((float) $in['reais'], (float) $in['base'])
        );
    }

    public function testCeilingToPercentMatchesTetoPct(): void
    {
        $this->assertGolden(
            'validar_plano.teto_pct',
            static fn (array $in): int => Num::ceilingToPercent((float) $in['reais'], (float) $in['base'])
        );
    }

    public function testPercentToAmountMatchesEmReais(): void
    {
        $this->assertGolden(
            'validar_plano.em_reais',
            static fn (array $in): int => (int) Num::percentToAmount((float) $in['pct'], (float) $in['base'])
        );
    }

    public function testEffectiveAmountMatchesEfetivo(): void
    {
        $this->assertGolden(
            'pace.efetivo',
            static function (array $in): float {
                $amount = (float) $in['reais'];
                $base = (float) $in['base'];
                $percent = $in['para_baixo'] ? Num::ceilingToPercent($amount, $base) : Num::floorToPercent($amount, $base);

                return Num::percentToAmount($percent, $base, 2);
            }
        );
    }

    /**
     * @param callable(array<string, mixed>): (int|float) $compute
     */
    private function assertGolden(string $function, callable $compute): void
    {
        $cases = Golden::cases($function);
        self::assertGreaterThan(30, count($cases), "$function has too few golden cases");

        $mismatches = [];
        foreach ($cases as $case) {
            $actual = $compute($case['input']);
            // Loose "==" so that 1760 and 1760.0 agree; the value itself must be identical.
            if ($actual != $case['expected']) {
                $mismatches[] = sprintf('%s => %s, expected %s', json_encode($case['input']), var_export($actual, true), var_export($case['expected'], true));
            }
        }

        self::assertSame([], array_slice($mismatches, 0, 5), sprintf('%d of %d cases of %s differ', count($mismatches), count($cases), $function));
    }
}
