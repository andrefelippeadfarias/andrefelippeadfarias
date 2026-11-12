<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Params\BandParams;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\Params\SwitchParams;

/**
 * The enabled relations bound to the room types: room sets resolved to codes, price
 * dependencies, groups of linked rooms and the resolution order.
 *
 * Order ({@see ordered()}): by group of linked rooms (the group of the first room in the room
 * list first), then by depth of the target (a relation runs after every relation that targets
 * one of its price inputs), then priority, position of the target in the room list and id.
 */
final class RelationGraph
{
    private const UNKNOWN_POSITION = PHP_INT_MAX;

    /** @var array<string, Relation> enabled relations by id */
    private array $relations = [];

    /** @var array<string, RoomType> */
    private array $rooms = [];

    /** @var array<string, int> room code => position in the room list */
    private array $position = [];

    /** @var array<string, list<string>> relation id => resolved members of its set */
    private array $members = [];

    /** @var array<string, list<string>> relation id => resolved rooms of its trigger set */
    private array $triggerMembers = [];

    /** @var array<string, list<string>> room => rooms whose price depends on it */
    private array $edges = [];

    /** @var array<string, string> room => root of its group */
    private array $parent = [];

    /** @var array<string, int> */
    private array $depth = [];

    /** @var list<Relation> */
    private array $ordered;

    /**
     * @param list<Relation> $relations disabled ones are ignored
     * @param list<RoomType> $rooms     every room type, in the room order of their properties
     *
     * @throws InvalidConfig when the relations make a room's price depend on itself
     */
    public function __construct(array $relations, array $rooms)
    {
        foreach ($rooms as $index => $room) {
            $this->rooms[$room->code] = $room;
            $this->position[$room->code] = $index;
        }
        foreach ($relations as $relation) {
            if ($relation->enabled) {
                $this->relations[$relation->id] = $relation;
                $this->resolveSets($relation, $rooms);
            }
        }
        foreach ($this->relations as $relation) {
            $this->link($relation);
        }
        $cycle = CycleFinder::find($this->edges);
        if ($cycle !== null) {
            throw new InvalidConfig('relations', [new ValidationError('relations.items', 'relations.cycle', self::cycleMessage($cycle))]);
        }
        $this->ordered = $this->sort();
    }

    /**
     * Rooms whose PRICE a relation reads. The trigger set of a switch is occupancy, not price.
     *
     * @return list<string>
     */
    public function priceInputs(Relation $relation): array
    {
        $params = $relation->params;

        return match (true) {
            $params instanceof AtLeastParams => [$params->reference],
            $params instanceof BetweenParams => $params->referenceRooms(),
            $params instanceof PremiumParams, $params instanceof BandParams => $this->members($relation),
            $params instanceof SwitchParams => $this->capMembers($params),
            default => [],
        };
    }

    /**
     * The resolved members of the set of a premium or band relation.
     *
     * @return list<string>
     */
    public function members(Relation $relation): array
    {
        return $this->members[$relation->id] ?? [];
    }

    /**
     * The resolved rooms whose occupancy triggers a switch.
     *
     * @return list<string>
     */
    public function triggerMembers(Relation $relation): array
    {
        return $this->triggerMembers[$relation->id] ?? [];
    }

    public function relation(string $id): ?Relation
    {
        return $this->relations[$id] ?? null;
    }

    public function room(string $code): ?RoomType
    {
        return $this->rooms[$code] ?? null;
    }

    /**
     * Every room linked to the given one by enabled relations (the room itself included).
     *
     * @return list<string>
     */
    public function groupOf(string $room): array
    {
        if (!isset($this->parent[$room])) {
            return [$room];
        }
        $root = $this->find($room);

        return array_values(array_filter(array_keys($this->parent), fn (string $code): bool => $this->find($code) === $root));
    }

    /**
     * Enabled relations in resolution order.
     *
     * @return list<Relation>
     */
    public function ordered(): array
    {
        return $this->ordered;
    }

    /**
     * Enabled relations of one stage, in resolution order.
     *
     * @return list<Relation>
     */
    public function orderedFor(RelationStage $stage): array
    {
        return array_values(array_filter($this->ordered, static fn (Relation $relation): bool => $relation->runsIn($stage)));
    }

    /**
     * The message of a cycle: the closed walk of rooms.
     *
     * @param list<string> $cycle
     */
    public static function cycleMessage(array $cycle): string
    {
        return sprintf(
            'These relations would make room prices depend on each other in a circle (%s). Remove or change one of them.',
            implode(' -> ', $cycle)
        );
    }

    /**
     * @param list<RoomType> $rooms
     */
    private function resolveSets(Relation $relation, array $rooms): void
    {
        $params = $relation->params;
        if ($params instanceof PremiumParams || $params instanceof BandParams) {
            $this->members[$relation->id] = $params->set->resolve($relation->target, $relation->propertyId, $rooms);
        }
        if ($params instanceof SwitchParams) {
            $this->triggerMembers[$relation->id] = $params->triggerSet->resolve($relation->target, $relation->propertyId, $rooms);
        }
    }

    /**
     * @return list<string>
     */
    private function capMembers(SwitchParams $params): array
    {
        $cap = $params->capRelation === null ? null : ($this->relations[$params->capRelation] ?? null);

        return $cap === null ? [] : $this->members($cap);
    }

    private function link(Relation $relation): void
    {
        $this->parent[$relation->target] ??= $relation->target;
        foreach ($this->priceInputs($relation) as $input) {
            if (!in_array($relation->target, $this->edges[$input] ?? [], true)) {
                $this->edges[$input][] = $relation->target;
            }
            $this->union($input, $relation->target);
        }
        foreach ($this->triggerMembers($relation) as $room) {
            $this->union($room, $relation->target);
        }
    }

    private function union(string $a, string $b): void
    {
        $this->parent[$a] ??= $a;
        $this->parent[$b] ??= $b;
        $this->parent[$this->find($a)] = $this->find($b);
    }

    private function find(string $room): string
    {
        while ($this->parent[$room] !== $room) {
            $room = $this->parent[$room];
        }

        return $room;
    }

    private function depthOf(string $room): int
    {
        if (!isset($this->depth[$room])) {
            $depth = 0;
            foreach ($this->edges as $from => $targets) {
                if (in_array($room, $targets, true)) {
                    $depth = max($depth, $this->depthOf((string) $from) + 1);
                }
            }
            $this->depth[$room] = $depth;
        }

        return $this->depth[$room];
    }

    private function rankOf(string $room): int
    {
        $rank = self::UNKNOWN_POSITION;
        foreach ($this->groupOf($room) as $member) {
            $rank = min($rank, $this->position[$member] ?? self::UNKNOWN_POSITION);
        }

        return $rank;
    }

    /**
     * @return list<Relation>
     */
    private function sort(): array
    {
        $keyed = [];
        foreach ($this->relations as $relation) {
            $keyed[] = [
                [$this->rankOf($relation->target), $this->depthOf($relation->target), $relation->priority,
                    $this->position[$relation->target] ?? self::UNKNOWN_POSITION, $relation->id],
                $relation,
            ];
        }
        usort($keyed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_column($keyed, 1);
    }
}
