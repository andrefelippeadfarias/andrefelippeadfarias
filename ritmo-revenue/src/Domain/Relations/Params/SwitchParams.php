<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RoomSet;

/**
 * When enough units of a set of rooms are sold, the target changes rule: it goes to a market price
 * (never below its safety floor and, while a member is free, never above the upper edge of a
 * discount band) or keeps its own price, and the relations it suspends do not run that night.
 */
final class SwitchParams implements RelationParams
{
    /** The only floor source of a switched price in this version: the safety floor of the room. */
    public const FLOOR_SAFETY = 'safety';

    private const MAX_UNITS = 10000.0;
    private const MAX_SUSPENDS = 20;

    /**
     * @param RoomSet                                                 $triggerSet  rooms whose occupancy is counted
     *                                                                             (occupancy only: no price edge)
     * @param array<string, array{segment: string, percentile: float}> $byNightGroup market target per night group
     *                                                                             (data for the market source)
     * @param string|null                                             $capRelation id of the discount band of the same
     *                                                                             target whose upper edge still caps
     * @param list<string>                                            $suspends    relations of the same target that do
     *                                                                             not run while the switch holds
     */
    public function __construct(
        public readonly RoomSet $triggerSet,
        public readonly TriggerUnit $unit,
        public readonly float $threshold,
        public readonly SwitchAction $action,
        public readonly array $byNightGroup = [],
        public readonly ?string $capRelation = null,
        public readonly array $suspends = [],
    ) {
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown(['trigger', 'then', 'cap_relation', 'floor', 'suspends']);
        $trigger = $reader->group('trigger');
        $trigger->rejectUnknown(['set', 'unit', 'threshold']);
        $unit = $trigger->choice('unit', TriggerUnit::class, TriggerUnit::Percent);
        $threshold = $unit === TriggerUnit::Percent
            ? $trigger->number('threshold', null, 0.0, 100.0)
            : $trigger->number('threshold', null, 1.0, self::MAX_UNITS);
        if ($unit === TriggerUnit::Percent && $threshold <= 0.0 && $trigger->has('threshold')) {
            $trigger->error('threshold', ValidationError::RANGE, 'must be greater than 0');
        }
        $reader->text('floor', self::FLOOR_SAFETY, '/^safety$/');
        $then = $reader->group('then');
        $then->rejectUnknown(['kind', 'by_night_group']);
        $action = $then->choice('kind', SwitchAction::class, null);

        return new self(
            RoomSet::read($trigger->group('set')),
            $unit,
            $threshold,
            $action,
            $action === SwitchAction::MarketPercentile ? self::readGroups($then) : [],
            $reader->optionalText('cap_relation', Relation::ID_PATTERN),
            $reader->texts('suspends', Relation::ID_PATTERN, self::MAX_SUSPENDS),
        );
    }

    /**
     * True when the sold units of the set reach the threshold. A percent threshold forgives the
     * float noise of an exact share (4 of 5 is 80%).
     */
    public function isHit(int $sold, int $units): bool
    {
        if ($this->unit === TriggerUnit::Units) {
            return $sold >= $this->threshold;
        }

        return $units > 0 && $sold / $units >= $this->threshold / 100 - 1e-9;
    }

    public function toArray(): array
    {
        $then = ['kind' => $this->action->value];
        if ($this->action === SwitchAction::MarketPercentile) {
            $then['by_night_group'] = $this->byNightGroup;
        }

        return [
            'trigger' => ['set' => $this->triggerSet->toArray(), 'unit' => $this->unit->value, 'threshold' => $this->threshold],
            'then' => $then,
            'cap_relation' => $this->capRelation,
            'floor' => self::FLOOR_SAFETY,
            'suspends' => $this->suspends,
        ];
    }

    /**
     * @return array<string, array{segment: string, percentile: float}>
     */
    private static function readGroups(Reader $then): array
    {
        $groups = [];
        $all = $then->group('by_night_group');
        foreach ($then->keysOf('by_night_group') as $name) {
            if (!is_string($name) || !Identifier::isSlug($name)) {
                $then->error('by_night_group', ValidationError::FORMAT, 'has a night group that is not a slug');
                continue;
            }
            $group = $all->group($name);
            $group->rejectUnknown(['segment', 'percentile']);
            $groups[$name] = [
                'segment' => $group->text('segment', null, Identifier::SLUG_PATTERN),
                'percentile' => $group->number('percentile', null, 0.0, 100.0),
            ];
        }
        if ($groups === []) {
            $then->error('by_night_group', ValidationError::REQUIRED, 'needs at least one night group');
        }

        return $groups;
    }
}
