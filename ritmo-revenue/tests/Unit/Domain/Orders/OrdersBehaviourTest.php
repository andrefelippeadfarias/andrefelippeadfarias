<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Orders;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Orders\ManualFreeze;
use Ritmo\Domain\Orders\OrderAuthority;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrderGate;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Orders\OwnerOrder;

final class OrdersBehaviourTest extends TestCase
{
    public function testAFullRowIsRead(): void
    {
        $order = (new OrderReader())->read([
            'id' => 'target-1', 'type' => 'target', 'rooms' => ['A1', 'B2'], 'from' => '2026-12-01', 'to' => '2026-12-10',
            'nights' => ['2026-12-02'], 'excluded_nights' => ['B2' => ['2026-12-02']], 'given_on' => '2026-11-28',
            'created_at' => '2026-11-28T10:00:00-03:00', 'releases' => ['below_standard_floor'], 'floors' => ['A1' => 700],
            'percentile' => 40, 'base_sold' => ['A1' => ['2026-12-02' => 2]], 'note' => 'sell the empty nights',
            'sales_ramp' => true, 'free_step' => true, 'only_down' => false, 'strong_night_occupancy' => 0.8, 'top' => ['A1' => 1500],
            'fallback_floors' => ['A1' => 600], 'fallback_trigger_days' => 5, 'fallback_min_days' => 2,
            'descent_floors' => ['A1' => [900, 800]], 'descent_marks' => [5, 2],
        ]);

        self::assertSame(OrderType::Target, $order->type);
        self::assertTrue($order->isInForceFor('A1', new DateTimeImmutable('2026-12-02')));
        self::assertFalse($order->isInForceFor('A1', new DateTimeImmutable('2026-12-03')), 'not a listed night');
        self::assertFalse($order->covers('B2', new DateTimeImmutable('2026-12-02')), 'moved to a newer order for that room');
        self::assertSame('2026-11-28T13:00:00+00:00', $order->createdAt?->format(DATE_ATOM));
        self::assertTrue($order->releases(OrderRelease::BelowStandardFloor));
        self::assertSame(700.0, $order->floorFor('A1'));
        self::assertSame(2, $order->baseSoldFor('A1', new DateTimeImmutable('2026-12-02')));
        self::assertTrue($order->terms->salesRamp);
        self::assertSame([900.0, 800.0], $order->terms->descentFloors['A1']);
        self::assertSame([5, 2], $order->terms->descentMarks);
        self::assertNull($order->terms->strongAheadOccupancy);
    }

    public function testEveryProblemOfARowIsReported(): void
    {
        try {
            (new OrderReader())->read(['id' => 'bad id!', 'type' => 'other', 'rooms' => [], 'from' => '2026-12-10', 'to' => '2026-12-01',
                'releases' => ['everything'], 'floors' => ['A1' => -1], 'created_at' => 'today', 'typo' => 1, 'sales_ramp' => 'yes']);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $error) {
            $paths = array_map(static fn ($e): string => $e->path, $error->errors());
        }

        foreach (['id', 'type', 'rooms', 'to', 'releases.0', 'floors.A1', 'created_at', 'typo', 'sales_ramp'] as $path) {
            self::assertContains($path, $paths);
        }
    }

    public function testAManualFreezeComesBeforeAFixedPrice(): void
    {
        $night = new DateTimeImmutable('2026-12-02');
        $book = new OrderBook([
            $this->order('fixed', OrderType::FixedPrice),
            $this->order('manual', OrderType::Manual),
            $this->order('fixed-2', OrderType::FixedPrice),
        ]);

        self::assertEquals(new OrderGate(OrderGate::MANUAL_FREEZE, ['manual']), $book->gateFor('A1', $night));
        self::assertEquals(new OrderGate(OrderGate::ORDER_FIXED_PRICE, ['fixed', 'fixed-2']), (new OrderBook([$this->order('fixed', OrderType::FixedPrice), $this->order('fixed-2', OrderType::FixedPrice)]))->gateFor('A1', $night));
        self::assertNull($book->gateFor('Z9', $night));
    }

    public function testTheBookRejectsRepeatedIdsAndReplacesOnRecord(): void
    {
        $book = new OrderBook([$this->order('a', OrderType::Rule), $this->order('b', OrderType::Rule)]);
        $again = $book->with($this->order('a', OrderType::Manual));

        self::assertSame(['b', 'a'], array_map(static fn (OwnerOrder $o): string => $o->id, $again->all()));
        self::assertSame(OrderType::Manual, $again->find('a')?->type);
        $this->expectException(InvalidArgumentException::class);
        new OrderBook([$this->order('a', OrderType::Rule), $this->order('a', OrderType::Rule)]);
    }

    public function testATargetOrderWithoutPercentileUsesTheConfiguredDefault(): void
    {
        $night = new DateTimeImmutable('2026-12-02');
        $named = new OwnerOrder('named', OrderType::Target, ['A1'], new DateTimeImmutable('2026-12-01'), new DateTimeImmutable('2026-12-05'), percentile: 45.0);
        $plain = $this->order('plain', OrderType::Target);

        self::assertSame('plain', (new OrderBook([$named, $plain], 40.0))->targetFor('A1', $night)?->id);
        self::assertSame('named', (new OrderBook([$named, $plain]))->targetFor('A1', $night)?->id);
    }

    public function testAnExceptionNeedsAnOrderInForceThatReleasesItInScope(): void
    {
        $night = new DateTimeImmutable('2026-12-02');
        $book = new OrderBook([
            $this->order('floor', OrderType::Floor, [OrderRelease::BelowStandardFloor]),
            $this->order('follow', OrderType::FollowUp, [OrderRelease::BelowStandardFloor]),
        ]);
        $authority = new OrderAuthority($book);
        $needs = [OrderRelease::BelowStandardFloor];

        self::assertNull($authority->check(null, 'A1', $night));
        self::assertNull($authority->check('floor', 'A1', $night, $needs));
        self::assertSame(OrderAuthority::ORDER_ID_MISSING, $authority->check(null, 'A1', $night, $needs));
        self::assertSame(OrderAuthority::ORDER_NOT_IN_FORCE, $authority->check('missing', 'A1', $night, $needs));
        self::assertSame(OrderAuthority::ORDER_NOT_IN_FORCE, $authority->check('follow', 'A1', $night, $needs));
        self::assertSame(OrderAuthority::ORDER_NOT_IN_FORCE, $authority->check('floor', 'Z9', $night, $needs));
        self::assertSame(OrderAuthority::ORDER_DOES_NOT_RELEASE, $authority->check('floor', 'A1', $night, [OrderRelease::FixedPrice]));
        self::assertSame(OrderAuthority::ORDER_OUT_OF_SCOPE, $authority->check('floor', 'A1', $night, $needs, static fn (): bool => false));
    }

    public function testNoItemTouchesANightFrozenByAnotherManualChange(): void
    {
        $night = new DateTimeImmutable('2026-12-02');
        $authority = new OrderAuthority(new OrderBook([$this->order('manual', OrderType::Manual), $this->order('floor', OrderType::Floor, [OrderRelease::BelowStandardFloor])]));

        self::assertSame(OrderAuthority::NIGHT_FROZEN_BY_OTHER_ORDER, $authority->check(null, 'A1', $night));
        self::assertSame(OrderAuthority::NIGHT_FROZEN_BY_OTHER_ORDER, $authority->check('floor', 'A1', $night, [OrderRelease::BelowStandardFloor]));
        self::assertNull($authority->check('manual', 'A1', $night));
    }

    public function testAManualChangeFreezesAtMostTheConfiguredDays(): void
    {
        $today = new DateTimeImmutable('2026-11-28');
        $order = (new ManualFreeze(3))->record('A1', $today, new DateTimeImmutable('2026-12-31'), $today);

        self::assertSame('manual-2026-11-28-a1-2026-11-28', $order->id);
        self::assertSame('2026-12-01', $order->to->format('Y-m-d'));
        self::assertSame(OrderType::Manual, $order->type);
        $this->expectException(InvalidArgumentException::class);
        (new ManualFreeze(3))->record('A1', new DateTimeImmutable('2026-12-10'), new DateTimeImmutable('2026-12-11'), $today);
    }

    /**
     * @param list<OrderRelease> $releases
     */
    private function order(string $id, OrderType $type, array $releases = []): OwnerOrder
    {
        return new OwnerOrder($id, $type, ['A1'], new DateTimeImmutable('2026-12-01'), new DateTimeImmutable('2026-12-05'), releases: $releases);
    }
}
