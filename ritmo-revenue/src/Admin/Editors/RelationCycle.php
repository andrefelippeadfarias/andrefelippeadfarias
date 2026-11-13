<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\CycleFinder;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;

/**
 * The loop a set of room relations would make, explained: the rooms in order and the relations
 * that link them. Each relation's links are the ones the engine's RelationGraph gives it alone
 * (with the relation it cites as a cap), so the loop is exactly the one the engine refuses.
 */
final class RelationCycle
{
    private function __construct()
    {
    }

    /**
     * @param list<Relation> $relations
     * @param list<RoomType> $rooms
     *
     * @return array{rooms: list<string>, relations: list<Relation>}|null null when there is no loop
     */
    public static function find(array $relations, array $rooms): ?array
    {
        $byId = [];
        foreach ($relations as $relation) {
            $byId[$relation->id] = $relation;
        }
        $edges = [];
        $owners = [];
        foreach ($relations as $relation) {
            if (!$relation->enabled) {
                continue;
            }
            foreach (self::inputs($relation, $byId, $rooms) as $input) {
                $edges[$input][] = $relation->target;
                $owners[$input . '>' . $relation->target] ??= $relation;
            }
        }
        $walk = CycleFinder::find(array_map(static fn (array $targets): array => array_values(array_unique($targets)), $edges));
        if ($walk === null) {
            return null;
        }
        $links = [];
        for ($i = 0; $i + 1 < count($walk); ++$i) {
            $links[] = $owners[$walk[$i] . '>' . $walk[$i + 1]];
        }

        return ['rooms' => $walk, 'relations' => $links];
    }

    /**
     * @param array<string, Relation> $byId
     * @param list<RoomType>          $rooms
     *
     * @return list<string>
     */
    private static function inputs(Relation $relation, array $byId, array $rooms): array
    {
        $alone = [$relation];
        $cap = $relation->params->toArray()['cap_relation'] ?? null;
        if (is_string($cap) && isset($byId[$cap]) && $cap !== $relation->id) {
            $alone[] = $byId[$cap];
        }
        try {
            return (new RelationGraph($alone, $rooms))->priceInputs($relation);
        } catch (InvalidConfig) {
            return [$relation->target];
        }
    }
}
