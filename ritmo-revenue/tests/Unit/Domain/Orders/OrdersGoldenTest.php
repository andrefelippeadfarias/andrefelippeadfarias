<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Orders;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Orders\ManualFreeze;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Tests\Support\Golden;
use Ritmo\Tests\Unit\Domain\Pricing\Ladder\Reference;

/**
 * Parity of the owner orders with ordens.py (cobre, ordem_valida, congelada, ordens_por_data,
 * piso_da_ordem, alvo_da_ordem, vendidas_base_escada, registrar_manual) and pace.sem_alvo.
 */
final class OrdersGoldenTest extends TestCase
{
    #[DataProvider('coverage')]
    public function testCovers(array $in, bool $expected): void
    {
        $order = (new OrderReader())->read(Reference::orderRow($in['order']));

        self::assertSame($expected, $order->covers($in['room'], Reference::date($in['night'])));
    }

    #[DataProvider('inForce')]
    public function testInForce(array $in, ?string $expected): void
    {
        self::assertSame($expected, Reference::orders($in['orders'])->inForce($in['order_id'], $in['room'], Reference::date($in['night']))?->id);
    }

    #[DataProvider('queries')]
    public function testQueries(array $in, array $expected): void
    {
        $book = Reference::orders($in['orders']);
        $night = Reference::date($in['night']);
        $room = $in['room'];

        self::assertSame($expected['manual_freeze'], $book->manualFreeze($room, $night)?->id);
        self::assertSame($expected['fixed_price'], array_map(static fn (OwnerOrder $o): string => $o->id, $book->fixedPrice($room, $night)));
        $floor = $book->floorFor($room, $night);
        self::assertSame($expected['floor'] === null, $floor === null);
        if ($floor !== null) {
            self::assertEqualsWithDelta($expected['floor']['amount'], $floor['amount'], Golden::AMOUNT_TOLERANCE);
            self::assertSame($expected['floor']['order_id'], $floor['order']->id);
        }
        self::assertSame($expected['target'], $book->targetFor($room, $night)?->id);
        $base = $book->baseSold($room, $night);
        self::assertSame($expected['base_sold'], $base === null ? null : ['sold' => $base['sold'], 'order_id' => $base['order']->id]);
        $left = array_map(static fn (OwnerOrder $o): string => $o->id, $book->withoutTargets()->all());
        sort($left);
        self::assertSame($expected['without_targets'], $left);
    }

    #[DataProvider('manual')]
    public function testManualFreeze(array $in, array $expected): void
    {
        $order = (new ManualFreeze($in['days']))->record($in['room'], Reference::date($in['from']), Reference::date($in['to']), Reference::date($in['today']), 'note');
        $book = Reference::orders(array_column($in['book'], null, 'id'))->with($order);

        self::assertSame($expected['order']['id'], $order->id);
        self::assertSame($expected['order']['de'], $order->from->format('Y-m-d'));
        self::assertSame($expected['order']['ate'], $order->to->format('Y-m-d'));
        self::assertSame($expected['order']['data'], $order->givenOn?->format('Y-m-d'));
        self::assertSame($expected['order']['quartos'], $order->rooms);
        self::assertSame($expected['book_ids'], array_map(static fn (OwnerOrder $o): string => $o->id, $book->all()));
    }

    public static function coverage(): array
    {
        return Reference::provider('ordens.cobre');
    }

    public static function inForce(): array
    {
        return Reference::provider('ordens.ordem_valida');
    }

    public static function queries(): array
    {
        return Reference::provider('ordens.consultas');
    }

    public static function manual(): array
    {
        return Reference::provider('ordens.registrar_manual');
    }
}
