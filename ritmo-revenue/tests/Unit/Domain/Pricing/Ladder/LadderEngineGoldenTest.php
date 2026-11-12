<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\Ladder\LadderActivation;
use Ritmo\Domain\Pricing\Ladder\LastMinuteMarketFloor;
use Ritmo\Domain\Pricing\Ladder\WeekendFloorOverride;
use Ritmo\Tests\Support\Golden;

/**
 * Parity of activation, hard floors, the weekend floor override and the last-minute market floor
 * with plano_dono.ativo and pace.piso_duro_do_plano / pisos_enviados / piso_semana_mercado.
 */
final class LadderEngineGoldenTest extends TestCase
{
    #[DataProvider('activation')]
    public function testActivation(array $in, bool $expected): void
    {
        self::assertSame($expected, LadderActivation::isActive(Reference::strategy(), $in['room'], Reference::date($in['night']), Reference::orders($in['orders'])));
    }

    #[DataProvider('hardFloors')]
    public function testHardFloor(array $in, array $expected): void
    {
        $floor = Reference::hardFloor()->for(Reference::room($in['room']), Reference::date($in['night']));

        self::assertEqualsWithDelta($expected['floor'], $floor['amount'], Golden::AMOUNT_TOLERANCE);
        self::assertSame($expected['label'], $floor['label']);
    }

    #[DataProvider('standardFloors')]
    public function testStandardFloorWithTheWeekendOverride(array $in, array $expected): void
    {
        $rules = Reference::rules();
        $room = Reference::room($in['room']);
        $night = Reference::date($in['night']);
        $override = new WeekendFloorOverride(Reference::strategy(), $rules->qualityFloor(), new PriceFactor($rules), new HolidayCalendar($rules));
        $floor = $override->standardFloor((new Floors($rules))->hardFloor($room, $night), $room, $night, Reference::orders($in['orders']));

        self::assertEqualsWithDelta($expected['floor'], $floor->amount, Golden::AMOUNT_TOLERANCE);
    }

    #[DataProvider('lastMinute')]
    public function testLastMinuteMarketFloor(array $in, ?array $expected): void
    {
        $floor = new LastMinuteMarketFloor(Reference::ladder(), Reference::ruler(), Reference::hardFloor(), new HolidayCalendar(Reference::rules()));
        $actual = $floor->floorFor($in['room'], Reference::date($in['night']), $in['D'], Reference::orders($in['orders']));
        if ($expected === null) {
            self::assertNull($actual);

            return;
        }
        self::assertNotNull($actual);
        self::assertEqualsWithDelta($expected['floor'], $actual['amount'], Golden::AMOUNT_TOLERANCE);
        self::assertSame($expected['order_id'], $actual['order']);
    }

    public static function activation(): array
    {
        return Reference::provider('plano_dono.ativo');
    }

    public static function hardFloors(): array
    {
        return Reference::provider('pace.piso_duro_do_plano');
    }

    public static function standardFloors(): array
    {
        return Reference::provider('pace.pisos_enviados');
    }

    public static function lastMinute(): array
    {
        return Reference::provider('pace.piso_semana_mercado');
    }
}
