<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Cadence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Tests\Support\Golden;
use Ritmo\Tests\Unit\Domain\Pricing\Ladder\Reference;

/**
 * Parity of the step cadence and the step ledger with pace.cadencia_permite, ordem_nova_sem_passo,
 * plano_no_ledger, registrar_passos and marcar_envio.
 */
final class CadenceGoldenTest extends TestCase
{
    #[DataProvider('permits')]
    public function testPermits(array $in, array $expected): void
    {
        $verdict = (new StepCadence())->permits(
            Reference::ledger($in['ledger']),
            $in['room'],
            Reference::date($in['night']),
            $in['direction'] === 'sobe' ? StepDirection::Up : StepDirection::Down,
            Reference::instant($in['now']),
            $in['sold'],
            $in['base'] === 'pousada' ? SoldBase::Property : SoldBase::Room,
            $in['total'],
        );

        self::assertSame($expected['allowed'], $verdict->allowed);
        self::assertSame($expected['hold_code'], $verdict->code);
    }

    #[DataProvider('bypass')]
    public function testNewOrderBypass(array $in, bool $expected): void
    {
        $order = $in['order'] === null ? null : Reference::orders([$in['order']['id'] => $in['order']])->find($in['order']['id']);

        self::assertSame($expected, (new StepCadence())->newOrderBypass(Reference::ledger($in['ledger']), $in['room'], Reference::date($in['night']), $order, Reference::date($in['today'])));
    }

    #[DataProvider('ladderSteps')]
    public function testHasLadderStep(array $in, bool $expected): void
    {
        self::assertSame($expected, Reference::ledger($in['ledger'])->hasLadderStep($in['room'], Reference::date($in['night'])));
    }

    #[DataProvider('records')]
    public function testRecord(array $in, array $expected): void
    {
        $proposals = [];
        foreach ($in['written_items'] as $item) {
            $step = $item['pace'];
            $proposals[] = new StepProposal(
                $item['quarto'],
                Reference::date($item['data']),
                $step['status'] === 'plano' ? StepRecord::LADDER : $step['status'],
                match ($step['direcao']) { 'sobe' => StepDirection::Up, 'desce' => StepDirection::Down, default => null },
                (float) $step['atual'],
                (float) $step['novo'],
                $step['alvo_enviado'],
                $step['vendidas'],
                $step['base'] === 'pousada' ? SoldBase::Property : SoldBase::Room,
                $step['total'],
            );
        }
        $ledger = Reference::ledger($in['ledger_before'])->record($proposals, Reference::instant($in['now']));

        self::assertCount(count($expected), $ledger->records());
        foreach ($expected as $i => $row) {
            self::assertRecord(Reference::stepRow($row), $ledger->records()[$i]);
        }
    }

    #[DataProvider('marks')]
    public function testMarkOnChannel(array $in, array $expected): void
    {
        $ledger = Reference::ledger($in['ledger']);
        $until = is_array($in['pushed_until'])
            ? array_map([Reference::class, 'instant'], $in['pushed_until'])
            : Reference::instant($in['pushed_until']);
        $marked = $ledger->markOnChannel($until);

        self::assertSame($expected['marked'], $ledger->pendingCount() - $marked->pendingCount());
        foreach ($expected['ledger'] as $i => $row) {
            self::assertRecord(Reference::stepRow($row), $marked->records()[$i]);
        }
    }

    public static function permits(): array
    {
        return Reference::provider('pace.cadencia_permite');
    }

    public static function bypass(): array
    {
        return Reference::provider('pace.ordem_nova_sem_passo');
    }

    public static function ladderSteps(): array
    {
        return Reference::provider('pace.plano_no_ledger');
    }

    public static function records(): array
    {
        return Reference::provider('pace.registrar_passos');
    }

    public static function marks(): array
    {
        return Reference::provider('pace.marcar_envio');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assertRecord(array $row, StepRecord $actual): void
    {
        self::assertSame($row['room'], $actual->roomCode);
        self::assertSame($row['night'], $actual->night->format('Y-m-d'));
        self::assertEqualsWithDelta((float) $row['price_from'], $actual->priceFrom, Golden::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta((float) $row['price_to'], $actual->priceTo, Golden::AMOUNT_TOLERANCE);
        self::assertSame($row['direction'], $actual->direction->value);
        self::assertSame($row['source_status'], $actual->sourceStatus);
        self::assertSame($row['sold'], $actual->sold);
        self::assertSame($row['sold_base'], $actual->soldBase->value);
        self::assertSame($row['sold_total'], $actual->soldTotal);
        self::assertEquals(Reference::instant($row['written_at']), $actual->writtenAt);
        if ($row['on_channel_at'] === null) {
            self::assertNull($actual->onChannelAt);
        } else {
            self::assertEquals(Reference::instant($row['on_channel_at']), $actual->onChannelAt);
        }
    }
}
