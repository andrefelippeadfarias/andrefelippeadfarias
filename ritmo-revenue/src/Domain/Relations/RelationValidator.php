<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Params\BandParams;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\Params\SwitchParams;

/**
 * Checks a relations configuration against the properties and room types it is saved with:
 * unknown properties and rooms, a room compared with itself, links between relations, and cycles
 * of price dependencies. A configuration with any error is not saved.
 *
 * Every enabled relation counts for the cycle check, whatever its scope and stages. A tag set
 * that matches no room is accepted: the relation simply has no member until a room gets the tag.
 */
final class RelationValidator
{
    public const UNKNOWN_PROPERTY = 'relations.unknown_property';
    public const UNKNOWN_ROOM = 'relations.unknown_room';
    public const SELF_REFERENCE = 'relations.self_reference';
    public const TARGET_IN_SET = 'relations.target_in_set';
    public const BAD_LINK = 'relations.bad_link';
    public const CYCLE = 'relations.cycle';

    /** @var list<ValidationError> */
    private array $errors = [];

    /**
     * @param list<RoomType> $rooms       every room type, in the room order of their properties
     * @param list<string>   $propertyIds
     *
     * @return list<ValidationError>
     */
    public function validate(RelationsConfig $config, array $rooms, array $propertyIds): array
    {
        $this->errors = [];
        $byId = [];
        foreach ($config->relations as $relation) {
            $byId[$relation->id] = $relation;
        }
        foreach ($config->relations as $index => $relation) {
            $this->checkRooms('relations.items.' . $index, $relation, $rooms, $propertyIds);
            $this->checkLinks('relations.items.' . $index, $relation, $byId);
        }
        foreach ($config->priceBands as $index => $band) {
            $path = 'price_bands.items.' . $index;
            if (!in_array($band->propertyId, $propertyIds, true)) {
                $this->add($path . '.property', self::UNKNOWN_PROPERTY, sprintf('Property "%s" does not exist.', $band->propertyId));
            } elseif (!self::roomOf($rooms, $band->propertyId, $band->room)) {
                $this->add($path . '.room', self::UNKNOWN_ROOM, sprintf('Room "%s" is not a room of property "%s".', $band->room, $band->propertyId));
            }
        }
        if ($this->errors === []) {
            $this->checkCycles($config, $rooms);
        }

        return $this->errors;
    }

    /**
     * @param list<RoomType> $rooms
     * @param list<string>   $propertyIds
     */
    private function checkRooms(string $path, Relation $relation, array $rooms, array $propertyIds): void
    {
        if (!in_array($relation->propertyId, $propertyIds, true)) {
            $this->add($path . '.property', self::UNKNOWN_PROPERTY, sprintf('Property "%s" does not exist.', $relation->propertyId));

            return;
        }
        foreach (['target' => $relation->target] + self::namedRooms($relation) as $field => $code) {
            if (!self::roomOf($rooms, $relation->propertyId, $code)) {
                $this->add($path . '.' . $field, self::UNKNOWN_ROOM, sprintf('Room "%s" is not a room of property "%s".', $code, $relation->propertyId));
            } elseif ($field !== 'target' && $code === $relation->target) {
                $errorCode = str_starts_with($field, 'params.set') || str_starts_with($field, 'params.trigger') ? self::TARGET_IN_SET : self::SELF_REFERENCE;
                $this->add($path . '.' . $field, $errorCode, sprintf('A room cannot be compared with itself (%s).', $code));
            }
        }
    }

    /**
     * @param array<string, Relation> $byId
     */
    private function checkLinks(string $path, Relation $relation, array $byId): void
    {
        $params = $relation->params;
        if (!$params instanceof SwitchParams) {
            return;
        }
        if ($params->capRelation !== null) {
            $cap = $byId[$params->capRelation] ?? null;
            if ($cap === null || !$cap->enabled || $cap->target !== $relation->target || !$cap->params instanceof BandParams) {
                $this->add($path . '.params.cap_relation', self::BAD_LINK, 'must be an enabled discount band of the same room.');
            }
        }
        foreach ($params->suspends as $index => $id) {
            $other = $byId[$id] ?? null;
            if ($other === null || !$other->enabled || $other->target !== $relation->target || $other->id === $relation->id) {
                $this->add($path . '.params.suspends.' . $index, self::BAD_LINK, 'must be another enabled relation of the same room.');
            }
        }
    }

    /**
     * @param list<RoomType> $rooms
     */
    private function checkCycles(RelationsConfig $config, array $rooms): void
    {
        $enabled = array_filter($config->relations, static fn (Relation $relation): bool => $relation->enabled);
        $walk = CycleFinder::find(self::edges($enabled, $rooms));
        if ($walk === null) {
            return;
        }
        $ids = self::relationsOn($walk, $enabled, $rooms);
        $this->add(
            'relations.items.' . (int) array_key_last($ids),
            self::CYCLE,
            sprintf('%s Relations involved: %s.', RelationGraph::cycleMessage($walk), implode(', ', $ids))
        );
    }

    /**
     * Ids of the relations whose edge lies on the walk, by their index in the configuration.
     *
     * @param list<string>         $walk
     * @param array<int, Relation> $relations
     * @param list<RoomType>       $rooms
     *
     * @return array<int, string>
     */
    private static function relationsOn(array $walk, array $relations, array $rooms): array
    {
        $ids = [];
        for ($step = 0; $step + 1 < count($walk); ++$step) {
            foreach ($relations as $index => $relation) {
                if ($relation->target === $walk[$step + 1] && in_array($walk[$step], self::inputsOf($relation, $relations, $rooms), true)) {
                    $ids[$index] = $relation->id;
                }
            }
        }
        ksort($ids);

        return $ids;
    }

    /**
     * @param array<int, Relation> $relations
     * @param list<RoomType>       $rooms
     *
     * @return array<string, list<string>>
     */
    private static function edges(array $relations, array $rooms): array
    {
        $edges = [];
        foreach ($relations as $relation) {
            foreach (self::inputsOf($relation, $relations, $rooms) as $input) {
                $edges[$input][] = $relation->target;
            }
        }

        return $edges;
    }

    /**
     * The price inputs of a relation, computed without a graph (the graph refuses a cycle).
     *
     * @param array<int, Relation> $relations
     * @param list<RoomType>       $rooms
     *
     * @return list<string>
     */
    private static function inputsOf(Relation $relation, array $relations, array $rooms): array
    {
        $params = $relation->params;
        if ($params instanceof SwitchParams) {
            foreach ($relations as $other) {
                if ($other->id === $params->capRelation && $other->params instanceof BandParams) {
                    return $other->params->set->resolve($other->target, $other->propertyId, $rooms);
                }
            }

            return [];
        }

        return match (true) {
            $params instanceof AtLeastParams => [$params->reference],
            $params instanceof BetweenParams => $params->referenceRooms(),
            $params instanceof PremiumParams, $params instanceof BandParams => $params->set->resolve($relation->target, $relation->propertyId, $rooms),
            default => [],
        };
    }

    /**
     * The rooms a relation names explicitly, by the path of the field that names them.
     *
     * @return array<string, string>
     */
    private static function namedRooms(Relation $relation): array
    {
        $params = $relation->params;
        $named = [];
        if ($params instanceof AtLeastParams) {
            $named['params.reference'] = $params->reference;
        } elseif ($params instanceof BetweenParams) {
            $keys = count($params->referenceRooms()) === 2 ? ['params.low_reference', 'params.high_reference'] : ['params.reference'];
            $named = array_combine($keys, $params->referenceRooms());
        } elseif ($params instanceof PremiumParams || $params instanceof BandParams) {
            foreach ($params->set->rooms as $index => $code) {
                $named['params.set.rooms.' . $index] = $code;
            }
        } elseif ($params instanceof SwitchParams) {
            foreach ($params->triggerSet->rooms as $index => $code) {
                $named['params.trigger.set.rooms.' . $index] = $code;
            }
        }

        return $named;
    }

    /**
     * @param list<RoomType> $rooms
     */
    private static function roomOf(array $rooms, string $propertyId, string $code): bool
    {
        foreach ($rooms as $room) {
            if ($room->code === $code && $room->propertyId === $propertyId) {
                return true;
            }
        }

        return false;
    }

    private function add(string $path, string $code, string $message): void
    {
        $this->errors[] = new ValidationError($path, $code, $message);
    }
}
