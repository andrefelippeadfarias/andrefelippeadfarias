<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\PaceBasis;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The sample inn plus a Suite of one unit with a tub and a Loft of two units without one.
 */
final class PaceBasisTest extends TestCase
{
    /**
     * @param list<string> $tags
     */
    private static function rules(int $maxUnits, array $tags = ['tub']): RuleSet
    {
        return EngineSample::rules(static function (array $data) use ($maxUnits, $tags): array {
            $data['pace']['group_pace'] = ['max_units' => $maxUnits, 'room_tags' => $tags];

            return $data;
        });
    }

    private static function suite(): RoomType
    {
        return new RoomType('inn', 'Suite', 'Suite', 1, '', '', 2000.0, 1200.0, 6000.0, 1300.0, ['tub']);
    }

    private static function loft(): RoomType
    {
        return new RoomType('inn', 'Loft', 'Loft', 2, '', '', 800.0, 500.0, 2400.0, 550.0);
    }

    /**
     * @return list<RoomType>
     */
    private static function rooms(): array
    {
        return [EngineSample::deluxe(), EngineSample::standard(), self::suite(), self::loft(), new RoomType('other', 'Annex', 'Annex', 3, '', '', 900.0, 300.0, 1500.0, 400.0, ['tub'])];
    }

    private const SALES = ['Deluxe' => [3, 4], 'Standard' => [1, 6], 'Suite' => [1, 1], 'Loft' => [0, 2], 'Annex' => [3, 3]];

    public function testASmallRoomIsReadWithTheTaggedRoomsOfItsProperty(): void
    {
        $basis = new PaceBasis(self::rules(2));

        self::assertSame([4, 5], $basis->groupFor(self::suite(), self::rooms(), self::SALES), 'Deluxe 3 of 4 + Suite 1 of 1; not the room of another property');
        self::assertSame([4, 5], $basis->groupFor(self::loft(), self::rooms(), self::SALES), 'a small room without the tag still reads the group, which it is not part of');
    }

    public function testARoomWithMoreUnitsIsReadOnItsOwn(): void
    {
        $basis = new PaceBasis(self::rules(2));

        self::assertNull($basis->groupFor(EngineSample::deluxe(), self::rooms(), self::SALES));
        self::assertNull((new PaceBasis(self::rules(1)))->groupFor(self::loft(), self::rooms(), self::SALES), 'two units, with the rule at one');
        self::assertSame([4, 5], (new PaceBasis(self::rules(1)))->groupFor(self::suite(), self::rooms(), self::SALES));
    }

    public function testTheRuleIsOffByDefault(): void
    {
        self::assertNull((new PaceBasis(EngineSample::rules()))->groupFor(self::suite(), self::rooms(), self::SALES));
        self::assertSame(0, RuleSet::defaults()->groupPaceMaxUnits());
    }

    public function testWithoutTagsTheGroupIsTheWholeProperty(): void
    {
        self::assertSame([5, 13], (new PaceBasis(self::rules(2, [])))->groupFor(self::suite(), self::rooms(), self::SALES), '3 + 1 + 1 + 0 of 4 + 6 + 1 + 2');
    }

    public function testOverbookedRoomsCountAsFullAndRoomsWithoutDataAreLeftOut(): void
    {
        $basis = new PaceBasis(self::rules(2));

        self::assertSame([5, 5], $basis->groupFor(self::suite(), self::rooms(), ['Deluxe' => [6, 4], 'Suite' => [1, 1]]));
        self::assertSame([1, 1], $basis->groupFor(self::suite(), self::rooms(), ['Suite' => [1, 1]]), 'only the Suite has data');
        self::assertNull($basis->groupFor(self::suite(), self::rooms(), ['Standard' => [1, 6]]), 'nothing of the group on sale: the room is read on its own');
        self::assertNull($basis->groupFor(self::suite(), self::rooms(), ['Deluxe' => [0, 0]]));
    }
}
