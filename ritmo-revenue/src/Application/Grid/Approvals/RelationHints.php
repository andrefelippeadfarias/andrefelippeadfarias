<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Days;

/**
 * Which rooms of a request are tied to each other on a night by an enabled relation of the
 * property (admin spec 5.4.4), so the screen can warn when a selection keeps one and drops the
 * other: the approved write validates the subset again and may refuse it as a whole. Pure.
 */
final class RelationHints
{
    /**
     * @param list<Relation>                        $relations the property's relations
     * @param array<string, list<string>>           $roomTags  room code => tags (a relation may name a set by tag)
     * @param Closure(DateTimeImmutable): NightType $nightType
     */
    public function __construct(
        private readonly array $relations,
        private readonly array $roomTags,
        private readonly Closure $nightType,
        private readonly DateTimeImmutable $today,
    ) {
    }

    /**
     * The linked pairs of a request: one per relation and night with at least two of its rooms.
     *
     * @param list<EntryRow> $entries
     *
     * @return list<array{relation: string, night: string, rooms: list<string>}>
     */
    public function pairs(array $entries): array
    {
        $roomsByNight = [];
        foreach ($entries as $entry) {
            if ($entry->night !== null) {
                $roomsByNight[Days::key($entry->night)][$entry->room] = true;
            }
        }
        $pairs = [];
        foreach ($roomsByNight as $night => $rooms) {
            $date = Days::date($night);
            foreach ($this->relations as $relation) {
                if (!$relation->enabled || !$relation->scope->matches($date, ($this->nightType)($date), $this->today)) {
                    continue;
                }
                $linked = array_values(array_intersect($this->rooms($relation), array_keys($rooms)));
                if (count($linked) >= 2) {
                    sort($linked);
                    $pairs[] = ['relation' => $relation->label, 'night' => $night, 'rooms' => $linked];
                }
            }
        }

        return $pairs;
    }

    /**
     * The pairs a selection splits: some of their rooms ticked on that night, others not.
     *
     * @param list<EntryRow> $entries
     * @param list<string>   $selected entry keys
     *
     * @return list<array{relation: string, night: string, rooms: list<string>}>
     */
    public function split(array $entries, array $selected): array
    {
        $chosen = array_flip($selected);
        $ticked = [];
        foreach ($entries as $entry) {
            if ($entry->night !== null) {
                $ticked[Days::key($entry->night)][$entry->room] = ($ticked[Days::key($entry->night)][$entry->room] ?? false) || isset($chosen[$entry->key]);
            }
        }

        return array_values(array_filter($this->pairs($entries), static function (array $pair) use ($ticked): bool {
            $states = array_map(static fn (string $room): bool => $ticked[$pair['night']][$room] ?? false, $pair['rooms']);

            return in_array(true, $states, true) && in_array(false, $states, true);
        }));
    }

    /**
     * Rooms named by a relation: its target and every room its parameters name by code or by tag
     * (a hint may name a room too many; it never misses one).
     *
     * @return list<string>
     */
    private function rooms(Relation $relation): array
    {
        $words = [$relation->target];
        $params = $relation->params->toArray();
        array_walk_recursive($params, static function (mixed $value) use (&$words): void {
            if (is_string($value)) {
                $words[] = $value;
            }
        });
        $rooms = [];
        foreach ($this->roomTags as $room => $tags) {
            if (in_array($room, $words, true) || array_intersect($tags, $words) !== []) {
                $rooms[] = $room;
            }
        }

        return $rooms;
    }
}
