<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Pricing\Cadence\CadenceConfig;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Ladder\LadderItemBuilder;
use Ritmo\Domain\Pricing\Ladder\LadderRoomQuote;
use Ritmo\Tests\Support\Golden;

/**
 * Parity of the whole-night evaluation and of the item builder with pace.plano_da_noite and
 * pace.item_do_plano.
 */
final class LadderNightGoldenTest extends TestCase
{
    #[DataProvider('nights')]
    public function testNight(array $in, array $expected): void
    {
        $fallback = $in['night_type_fallback'] === null ? null : Reference::label($in['night_type_fallback']);
        $rows = $blocked = [];
        foreach ($in['calendars'] as $room => $nights) {
            foreach ($nights as $row) {
                $rows[$room] = Reference::priceNight($room, $row);
            }
        }
        foreach ($in['blocks'] as [$room]) {
            $blocked[$room] = true;
        }
        $night = Reference::evaluator(static fn (DateTimeImmutable $n): ?string => $fallback)
            ->evaluate(Reference::date($in['night']), $in['D'], $rows, Reference::orders($in['orders']), $blocked);

        if (isset($expected['_sem_regua'])) {
            self::assertTrue($night->rulerGap);
            self::assertSame([], $night->quotes);

            return;
        }
        self::assertFalse($night->rulerGap);
        self::assertSame(array_keys($expected), array_keys($night->quotes), 'rooms priced, in processing order');
        foreach ($expected as $room => $quote) {
            self::assertQuote($quote, $night->quotes[$room], $room);
        }
    }

    #[DataProvider('items')]
    public function testItem(array $in, array $expected): void
    {
        $rules = Reference::rules();
        $builder = new LadderItemBuilder(Reference::strategy(), new StepCadence(), new CadenceConfig(), $rules->tolerance());
        $room = Reference::room($in['room']);
        $outcome = $builder->build(
            $room,
            self::quote($in['room'], $in['ladder']),
            $in['D'],
            Reference::priceNight($in['room'], $in['calendar_row']),
            Reference::override($in['night'], $in['live_rule']),
            Reference::ledger($in['ledger']),
            Reference::instant($in['now']),
            $in['in_filter'],
            $in['listing_top'],
        );
        $line = $expected['line'];
        self::assertSame($line['novo'], $outcome->line->new);
        self::assertSame($line['direcao'] === null ? null : ($line['direcao'] === 'sobe' ? 'up' : 'down'), $outcome->line->direction?->value);
        self::assertSame($line['hold_code'], $outcome->line->hold);
        self::assertSame($line['vendido_pct'], $outcome->line->soldPercent);
        self::assertSame($line['alvo_publico'], $outcome->line->targetPublic);
        self::assertSame($line['alvo_enviado'], $outcome->line->targetSent);
        self::assertSame($line['atual_publico'], $outcome->line->livePublic);
        if ($expected['item'] === null) {
            self::assertNull($outcome->item);

            return;
        }
        $item = $expected['item'];
        self::assertNotNull($outcome->item);
        self::assertEqualsWithDelta($item['min_price'], $outcome->item->minPrice, Golden::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta($item['max_price'], $outcome->item->maxPrice, Golden::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta($item['preco_antes'], $outcome->item->priceBefore, Golden::AMOUNT_TOLERANCE);
        self::assertSame($item['ordem_id'], $outcome->item->authorityOrderId);
        self::assertSame($item['pace']['alvo_enviado'], $outcome->item->step->targetSent);
        self::assertSame($item['pace']['vendidas'], $outcome->item->step->sold);
        self::assertSame($item['pace']['total'], $outcome->item->step->soldTotal);
        self::assertSame('ladder', $outcome->item->step->sourceStatus);
    }

    public static function nights(): array
    {
        return Reference::provider('pace.plano_da_noite');
    }

    public static function items(): array
    {
        return Reference::provider('pace.item_do_plano');
    }

    /**
     * @param array<string, mixed> $q
     */
    private static function quote(string $room, array $q): LadderRoomQuote
    {
        return new LadderRoomQuote(
            $room,
            Reference::label($q['tipo']),
            $q['enviado'],
            $q['publico'],
            $q['abertura'],
            $q['degraus'],
            $q['desceu'],
            $q['vendidas'],
            $q['total'],
            $q['piso_duro'],
            'safety_limit',
            $q['fator'],
            false,
            $q['limites'],
        );
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function assertQuote(array $expected, LadderRoomQuote $actual, string $room): void
    {
        $t = Golden::AMOUNT_TOLERANCE;
        self::assertEqualsWithDelta($expected['enviado'], $actual->sent, $t, "$room sent");
        self::assertEqualsWithDelta($expected['publico'], $actual->public, $t, "$room public");
        if ($expected['abertura'] === null) {
            self::assertNull($actual->opening, "$room opening");
        } else {
            self::assertEqualsWithDelta($expected['abertura'], $actual->opening, $t, "$room opening");
        }
        self::assertSame($expected['degraus'], $actual->steps, "$room steps");
        self::assertSame($expected['desceu'], $actual->dropped, "$room dropped");
        self::assertSame($expected['vendidas'], $actual->sold, "$room sold");
        self::assertSame($expected['total'], $actual->total, "$room total");
        self::assertEqualsWithDelta($expected['piso_duro'], $actual->hardFloor, $t, "$room hard floor");
        self::assertEqualsWithDelta($expected['fator'], $actual->factor, 1e-9, "$room factor");
        self::assertSame(Reference::label($expected['tipo']), $actual->nightType);
        if ($expected['limites'] === null) {
            self::assertNull($actual->limits, "$room limits");
        } else {
            self::assertNotNull($actual->limits, "$room limits");
            foreach ([0, 1] as $i) {
                $expected['limites'][$i] === null
                    ? self::assertNull($actual->limits[$i])
                    : self::assertEqualsWithDelta($expected['limites'][$i], $actual->limits[$i], $t, "$room limit $i");
            }
        }
        self::assertSame($expected['relation_reasons'], $actual->relationReasons, "$room relation reasons");
        if (array_key_exists('base_enviado', $expected)) {
            self::assertEqualsWithDelta($expected['base_enviado'], $actual->baseSent, $t, "$room base");
        }
    }
}
