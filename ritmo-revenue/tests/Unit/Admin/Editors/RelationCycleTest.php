<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Editors;

use PHPUnit\Framework\TestCase;
use Ritmo\Admin\Editors\RelationCycle;
use Ritmo\Admin\Editors\RelationForm;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationType;

/**
 * A loop of relations is found with the rooms it walks and the relations that link them, in the
 * order of the walk, so the screen can name them (spec 05, 5.6, AC-REL-2); a relation turned off
 * breaks the loop.
 */
final class RelationCycleTest extends TestCase
{
    public function testTheLoopIsWalkedWithItsRoomsAndRelations(): void
    {
        $relations = [self::relation('rel-b', 'room-b', 'room-a'), self::relation('rel-c', 'room-c', 'room-b'), self::relation('rel-a', 'room-a', 'room-c')];

        $cycle = RelationCycle::find($relations, self::rooms());

        self::assertNotNull($cycle);
        $walk = $cycle['rooms'];
        self::assertSame($walk[0], $walk[count($walk) - 1], 'the walk comes back to its first room');
        self::assertCount(4, $walk);
        self::assertEqualsCanonicalizing(['room-a', 'room-b', 'room-c'], array_slice($walk, 0, 3));
        self::assertCount(3, $cycle['relations']);
        foreach ($cycle['relations'] as $index => $relation) {
            self::assertSame($walk[$index + 1], $relation->target, 'each relation makes its room depend on the one before it');
        }
    }

    public function testNoLoopWithoutTheClosingRelationOrWhenItIsOff(): void
    {
        $open = [self::relation('rel-b', 'room-b', 'room-a'), self::relation('rel-c', 'room-c', 'room-b')];
        self::assertNull(RelationCycle::find($open, self::rooms()));

        $off = [...$open, self::relation('rel-a', 'room-a', 'room-c', false)];
        self::assertNull(RelationCycle::find($off, self::rooms()));
    }

    public function testTwoRoomsThatNeedEachOtherAreALoop(): void
    {
        $cycle = RelationCycle::find([self::relation('rel-b', 'room-b', 'room-a'), self::relation('rel-a', 'room-a', 'room-b')], self::rooms());

        self::assertNotNull($cycle);
        self::assertCount(3, $cycle['rooms']);
        self::assertSame(['rel-a', 'rel-b'], self::sorted(array_map(static fn (Relation $r): string => $r->id, $cycle['relations'])));
    }

    private static function relation(string $id, string $target, string $reference, bool $enabled = true): Relation
    {
        $schema = RelationForm::relationSchema(RelationType::AtLeast);
        $item = $schema->withDefaults(['params' => ['reference' => $reference]]);
        $item['id'] = $id;
        $item['type'] = RelationType::AtLeast->value;
        $item['property'] = 'inn-1';
        $item['target'] = $target;
        $item['enabled'] = $enabled;

        return Relation::fromArray($item);
    }

    /**
     * @return list<RoomType>
     */
    private static function rooms(): array
    {
        return array_map(
            static fn (string $code): RoomType => new RoomType('inn-1', $code, 'Room ' . strtoupper(substr($code, -1)), 2, 'pms-' . $code, 'pl-' . $code, 600.0, 400.0, 1200.0, 400.0),
            ['room-a', 'room-b', 'room-c']
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
