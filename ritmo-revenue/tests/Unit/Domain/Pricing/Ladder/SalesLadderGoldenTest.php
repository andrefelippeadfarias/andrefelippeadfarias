<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Pricing\Ladder\RulerNight;
use Ritmo\Tests\Support\Golden;

/**
 * Parity of the stepped ladder with plano_dono.py (golden m2/ladder/plano_dono.*).
 */
final class SalesLadderGoldenTest extends TestCase
{
    #[DataProvider('steps')]
    public function testSteps(array $in, int $expected): void
    {
        self::assertSame($expected, Reference::ladder()->steps($in['vendidas'], $in['unidades']));
    }

    #[DataProvider('drops')]
    public function testDrop(array $in, bool $expected): void
    {
        $ladder = Reference::ladder();
        $rule = $ladder->ruleFor($in['room'], Reference::label($in['tipo']));

        self::assertSame($expected, $rule !== null && $ladder->dropApplies($in['D'], $in['vendidas'], $in['unidades'], $rule));
    }

    #[DataProvider('governed')]
    public function testGoverned(array $in, bool $expected): void
    {
        self::assertSame($expected, Reference::ladder()->ruleFor($in['room'], Reference::label($in['tipo'])) !== null);
    }

    #[DataProvider('openings')]
    public function testOpening(array $in, mixed $expected): void
    {
        self::assertAmount($expected, Reference::ladder()->openingPublic($in['room'], self::rulerNight($in)));
    }

    #[DataProvider('ceilings')]
    public function testCeiling(array $in, mixed $expected): void
    {
        self::assertAmount($expected, Reference::ladder()->ceilingPublic($in['room'], self::rulerNight($in)));
    }

    #[DataProvider('prices')]
    public function testPublicPrice(array $in, ?array $expected): void
    {
        $quote = Reference::ladder()->publicPrice($in['room'], self::rulerNight($in), $in['vendidas'], $in['unidades'], $in['D'], $in['publico_double'] ?? null);
        if ($expected === null) {
            self::assertNull($quote);

            return;
        }
        self::assertNotNull($quote);
        self::assertEqualsWithDelta($expected['publico'], $quote->public, Golden::AMOUNT_TOLERANCE);
        self::assertAmount($expected['abertura'], $quote->opening);
        self::assertAmount($expected['teto'], $quote->ceiling);
        self::assertSame($expected['degraus'], $quote->steps);
        self::assertSame($expected['desceu'], $quote->dropped);
    }

    #[DataProvider('sent')]
    public function testToSent(array $in, float $expected): void
    {
        $ladder = Reference::ladder();
        $ruler = self::rulerNight($in);
        $rule = $ladder->ruleFor($in['room'], $ruler->type);
        self::assertNotNull($rule);

        self::assertEqualsWithDelta($expected, $ladder->toSent($in['room'], $ruler, (float) $in['publico'], $rule), Golden::AMOUNT_TOLERANCE);
    }

    public static function steps(): array
    {
        return Reference::provider('plano_dono.degraus_escada');
    }

    public static function drops(): array
    {
        return Reference::provider('plano_dono.desce');
    }

    public static function governed(): array
    {
        return Reference::provider('plano_dono.governa');
    }

    public static function openings(): array
    {
        return Reference::provider('plano_dono.abertura_publico');
    }

    public static function ceilings(): array
    {
        return Reference::provider('plano_dono.teto_publico');
    }

    public static function prices(): array
    {
        return Reference::provider('plano_dono.preco_publico');
    }

    public static function sent(): array
    {
        return Reference::provider('plano_dono.enviado');
    }

    /**
     * @param array<string, mixed> $in
     */
    private static function rulerNight(array $in): RulerNight
    {
        if (isset($in['ruler_night'])) {
            return Reference::rulerNight('2026-11-13', $in['ruler_night']);
        }
        $night = Reference::ruler()->night(Reference::date($in['night']));
        self::assertNotNull($night);

        return $night;
    }

    private static function assertAmount(mixed $expected, ?float $actual): void
    {
        if ($expected === null) {
            self::assertNull($actual);

            return;
        }
        self::assertIsNumeric($expected, 'unexpected golden value ' . json_encode($expected));
        self::assertNotNull($actual);
        self::assertEqualsWithDelta((float) $expected, $actual, Golden::AMOUNT_TOLERANCE);
    }
}
