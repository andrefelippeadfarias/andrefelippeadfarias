<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Rules;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;

final class OrderDeskTest extends TestCase
{
    public function testAMinimumPriceOrderIsCreatedListedAndEnded(): void
    {
        $world = new RulesWorld();
        $id = $world->orders->create(RulesWorld::PROPERTY, ['type' => 'floor', 'rooms' => ['room-a'], 'from' => '2026-11-20', 'to' => '2026-11-22', 'floors' => ['room-a' => '450'], 'note' => 'Long weekend'], 7, null, false);

        $active = $world->board->active(RulesWorld::PROPERTY, Days::date('2026-11-13'));
        self::assertCount(1, $active);
        $order = $active[0]['order'];
        self::assertSame([$id, OrderType::Floor, ['room-a'], 450.0, 'Long weekend', 7], [$active[0]['id'], $order?->type, $order?->rooms, $order?->floorFor('room-a'), $order?->note, $active[0]['created_by']]);

        $world->orders->end(RulesWorld::PROPERTY, $id, 8);

        self::assertSame([], $world->board->active(RulesWorld::PROPERTY, Days::date('2026-11-13')));
        $ended = $world->board->ended(RulesWorld::PROPERTY, Days::date('2026-11-13'));
        self::assertSame([$id, '2026-11-13T13:00:00+00:00'], [$ended[0]['id'], $ended[0]['ended_at']?->format(DATE_ATOM)]);
        self::assertSame(['Order ended', 'Order created'], array_column($world->events->latest(RulesWorld::PROPERTY, [EditorEventRepository::ORDER]), 'reason'));
        self::assertSame('user:8', $world->events->latest(RulesWorld::PROPERTY, [EditorEventRepository::ORDER])[0]['actor']);
        $this->expectException(RulesRefused::class);
        $world->orders->end(RulesWorld::PROPERTY, $id, 8);
    }

    public function testFormProblemsAreNamedByField(): void
    {
        $world = new RulesWorld();
        $cases = [
            [['type' => 'floor', 'rooms' => ['room-a'], 'from' => '2026-11-22', 'to' => '2026-11-20', 'floors' => ['room-a' => '450']], ['to']],
            [['type' => 'floor', 'rooms' => ['room-a'], 'from' => '2026-11-20', 'to' => '2026-11-22'], ['floors.room-a']],
            [['type' => 'surprise', 'rooms' => ['room-z'], 'from' => 'x', 'to' => '2026-11-22'], ['type', 'rooms', 'from']],
            [['type' => 'target', 'rooms' => ['*'], 'from' => '2026-11-20', 'to' => '2026-11-22', 'percentile' => '150'], ['percentile']],
            [['type' => 'restriction', 'rooms' => ['room-a'], 'from' => '2026-11-20', 'to' => '2026-11-22', 'releases' => ['listing']], ['releases']],
            [['type' => 'follow_up', 'rooms' => ['*'], 'from' => '2026-11-20', 'to' => '2026-11-22', 'note' => 'call +55 11 98765-4321'], ['note']],
        ];
        foreach ($cases as [$fields, $paths]) {
            try {
                $world->orders->create(RulesWorld::PROPERTY, $fields, 7, null, true);
                self::fail(json_encode($fields));
            } catch (InvalidConfig $invalid) {
                self::assertSame($paths, array_map(static fn (ValidationError $e): string => $e->path, $invalid->errors()), json_encode($fields));
            }
        }
        self::assertSame([], $world->board->active(RulesWorld::PROPERTY, Days::date('2026-11-13')));
    }

    public function testASafetyReleaseNeedsTheCapabilityAndThePropertyIdTyped(): void
    {
        $world = new RulesWorld();
        $fields = ['type' => 'floor', 'rooms' => ['room-a'], 'from' => '2026-11-20', 'to' => '2026-11-22', 'floors' => ['room-a' => '350'], 'releases' => ['below_standard_floor']];
        foreach ([[null, false, RulesRefused::RELEASE_NOT_ALLOWED], ['inn-2', true, RulesRefused::CONFIRMATION]] as [$typed, $may, $reason]) {
            try {
                $world->orders->create(RulesWorld::PROPERTY, $fields, 7, $typed, $may);
                self::fail($reason);
            } catch (RulesRefused $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }
        self::assertSame([], $world->board->active(RulesWorld::PROPERTY, Days::date('2026-11-13')));

        $id = $world->orders->create(RulesWorld::PROPERTY, $fields, 7, 'inn-1', true);

        self::assertTrue($world->board->active(RulesWorld::PROPERTY, Days::date('2026-11-13'))[0]['order']?->releases(OrderRelease::BelowStandardFloor));
        self::assertGreaterThan(0, $id);
    }

    public function testACopyCanEndTheOriginalAndOnlyTheseNightsAreKept(): void
    {
        $world = new RulesWorld();
        $first = $world->orders->create(RulesWorld::PROPERTY, ['type' => 'fixed_price', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-26'], 7, null, false);
        $copy = $world->orders->create(RulesWorld::PROPERTY, ['type' => 'fixed_price', 'rooms' => ['room-b'], 'nights' => ['2026-12-31', '2026-12-24']], 7, null, false, $first);

        $active = $world->board->active(RulesWorld::PROPERTY, Days::date('2026-11-13'));
        self::assertSame([$copy], array_column($active, 'id'));
        $order = $active[0]['order'];
        self::assertTrue($order?->releases(OrderRelease::FixedPrice));
        self::assertTrue($order?->isInForceFor('room-b', Days::date('2026-12-31')));
        self::assertFalse($order?->isInForceFor('room-b', Days::date('2026-12-25')), 'only the nights listed');
        self::assertFalse($order?->isInForceFor('room-a', Days::date('2026-12-31')));
    }

    public function testAFreezeIsReleasedOnceWithItsEvent(): void
    {
        $world = new RulesWorld();
        $id = $world->freezes->add(RulesWorld::PROPERTY, new DetectedFreeze('room-a', [Days::date('2026-11-20')], new DateTimeImmutable('2026-11-12T10:00:00Z'), null, ['min_stay']));
        self::assertCount(1, $world->board->freezes(RulesWorld::PROPERTY, Days::date('2026-11-13'))['detected']);

        $world->orders->releaseFreeze(RulesWorld::PROPERTY, $id, 9);

        self::assertSame([], $world->board->freezes(RulesWorld::PROPERTY, Days::date('2026-11-13'))['detected']);
        self::assertSame('user:9', $world->operationEvents->latest(RulesWorld::PROPERTY, OperationEventRepository::FREEZE_RELEASE)[0]['actor']);
        $this->expectException(RulesRefused::class);
        $world->orders->releaseFreeze(RulesWorld::PROPERTY, $id, 9);
    }
}
