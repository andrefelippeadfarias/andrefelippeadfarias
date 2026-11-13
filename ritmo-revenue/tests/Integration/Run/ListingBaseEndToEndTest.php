<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * The percentages a run writes are over the listing base the pricing system applies now. Review
 * finding of the M3 correction phase (blocking): the base typed at setup was used forever, so a
 * base changed in the pricing system made every floor of the room wrong and tonight's guard
 * convert with the wrong base (tonight's price could rise).
 */
final class ListingBaseEndToEndTest extends TestCase
{
    public function testARunWritesOverTheBaseItReadAndStoresTheChangeWithAnAlert(): void
    {
        $control = self::world();
        $control->run();

        $drifted = self::world();
        $stored = $drifted->stack->roomTypes()->find('STD') ?? throw new \LogicException('no room');
        $drifted->stack->roomTypes()->save(self::withBase($stored, 300.0));
        $run = $drifted->run();

        self::assertSame(self::bodies($control), self::bodies($drifted), 'the same writes as with the right base: the run used the base it read');
        self::assertSame(1, $drifted->summary($run->runId)['watch']['listing_changes'] ?? null);
        self::assertSame(480.0, $drifted->stack->roomTypes()->find('STD')?->base, 'the stored base follows the pricing system');
        $alert = (new AlertLedger($drifted->db))->latestByKey('listing_changed_manually:inn:' . $stored->pricingRef);
        self::assertSame(['base' => 300.0], $alert?->context['before'] ?? null);
        self::assertNotSame([], $drifted->stack->events()->latest(RunWorld::PROPERTY, OperationEventRepository::LISTING), 'the change is in the history');

        $drifted->later('+3 hours');
        $next = $drifted->run();
        self::assertSame(0, $drifted->summary($next->runId)['watch']['listing_changes'] ?? null, 'raised once');
    }

    private static function world(): RunWorld
    {
        return new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]]);
    }

    private static function withBase(RoomType $room, float $base): RoomType
    {
        return new RoomType($room->propertyId, $room->code, $room->name, $room->units, $room->pmsRef, $room->pricingRef, $base, $room->min, $room->max, $room->referenceMin, $room->tags);
    }

    /**
     * @return list<string>
     */
    private static function bodies(RunWorld $world): array
    {
        return array_map(static fn (array $w): string => $w['method'] . ' ' . $w['path'] . ' ' . json_encode($w['body']), $world->apis->writeBodies());
    }
}
