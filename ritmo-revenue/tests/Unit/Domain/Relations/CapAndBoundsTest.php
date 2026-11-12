<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Coherence\PlanDraft;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\ListingCapChain;
use Ritmo\Domain\Relations\Port\SentBand;
use Ritmo\Domain\Relations\PremiumBounds;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Support\Days;

final class CapAndBoundsTest extends TestCase
{
    /**
     * MID between LOW and HIGH; HIGH has a listing maximum of 3000.
     */
    private static function chain(): ListingCapChain
    {
        $rooms = array_map(
            static fn (RoomType $room): RoomType => $room->code === 'HIGH' ? RoomType::fromArray(['max' => 3000.0] + $room->toArray()) : $room,
            RelationsSample::rooms()
        );
        $between = RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH']);

        return new ListingCapChain(new RelationGraph([$between], $rooms), new RelationSettings());
    }

    public function testCapsAreChainedFromTheHighReferenceDown(): void
    {
        $chain = self::chain();

        self::assertSame(2989.6, $chain->capFor('HIGH'), '102% over 1480');
        self::assertSame(2989.2, $chain->capFor('MID'), 'under HIGH, on the grid of 1590 (88%)');
        self::assertSame(2979.42, $chain->capFor('LOW'), 'under MID, on the grid of 1173 (154%)');
        self::assertSame(5000.0, $chain->capFor('TOP'), 'no chain: its own maximum');
    }

    public function testTheLastPassCutsFloorsAndCeilingsAtTheCap(): void
    {
        $night = Days::date(RelationsSample::NIGHT);
        $above = new DraftItem('MID', $night, 3000.0, 3100.0, 3200.0);
        $atTop = new DraftItem('LOW', $night, 3000.0, 2970.0, 3500.0);
        $protected = new DraftItem('HIGH', $night, 3000.0, 2989.0, 3500.0, null, false, false, true);
        $far = new DraftItem('TOP', $night, 3000.0, 2000.0, 6000.0);

        $capped = self::chain()->apply(new PlanDraft([$above, $atTop, $protected, $far]));

        self::assertSame([$above], $capped);
        self::assertSame([2989.0, 2989.0], [$above->floor(), $above->ceiling()]);
        self::assertSame([2970.0, 2979.0], [$atTop->floor(), $atTop->ceiling()], 'within one grid step of the cap: the ceiling follows');
        self::assertSame([2989.0, 3500.0], [$protected->floor(), $protected->ceiling()], 'a sold-out protection keeps its free ceiling');
        self::assertSame([2000.0, 6000.0], [$far->floor(), $far->ceiling()]);
    }

    public function testBoundsOfTheTopRoomComeFromTheDearestFreeMemberAndItsBand(): void
    {
        $night = Days::date('2027-03-06');
        $premium = RelationsSample::relation('top', 'premium_over_max', 'TOP', ['set' => ['room_tags' => ['tub']], 'premium_percent' => ['weekday' => 20, 'weekend' => 5]]);
        $graph = new RelationGraph([$premium], RelationsSample::rooms());
        $calendar = RelationsSample::calendar(['LOW' => ['2027-03-06' => [2000, 1]], 'HIGH' => ['2027-03-06' => [2100, 3]], 'MID' => ['2027-03-06' => [2050, 0]]]);
        $facts = new NightFacts(RelationsSample::rooms(), $calendar, ['LOW|2027-03-06' => 2080.0]);
        $hooks = new StubHooks();
        $hooks->bands['TOP|2027-03-06'] = new SentBand(2540.0, 2857.0);
        $bound = static fn (string $last): PremiumBounds => PremiumBounds::forNight($premium, $graph->members($premium), $night, $facts, $hooks->bundle()->calendar, $hooks, Days::date('2027-03-01'));

        $hooks->bandLastNight = '2027-03-06';
        self::assertEquals(new PremiumBounds(2540.0, 2857.0), $bound('inside'), 'HIGH is sold out; 2080 x 1.05 = 2184 is below the band');
        $hooks->bandLastNight = '2027-03-05';
        $outside = $bound('beyond');
        self::assertEqualsWithDelta(2184.0, $outside->floor, 1e-9);
        self::assertNull($outside->ceiling);
        $empty = new NightFacts(RelationsSample::rooms(), []);
        self::assertEquals(new PremiumBounds(0.0, null), PremiumBounds::forNight($premium, $graph->members($premium), $night, $empty, $hooks->bundle()->calendar, $hooks, Days::date('2027-03-01')));
    }
}
