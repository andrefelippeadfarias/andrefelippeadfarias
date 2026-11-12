<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Relations\MemberPolicy;
use Ritmo\Domain\Relations\RoomSet;

/**
 * The target between two percents of the cheapest free room of a set.
 */
final class BandParams implements RelationParams
{
    private const MAX_MARGIN = 1000.0;
    private const MAX_EXTRA = 100.0;

    /**
     * @param float           $referenceRoundingMargin currency added to the reference for the lower edge
     *                                                 (the reference moves on its own percent grid)
     * @param float           $lowerExtraAmount        currency added to the lower edge after rounding up
     * @param list<NightType> $downOnlyNightTypes      nights on which the band only lowers the target, never
     *                                                 below its own floor
     * @param bool            $orderFloorYields        a floor from an owner floor order above the upper edge gives
     *                                                 way to the lowest floor the room still has
     * @param bool            $followStrategyReference on a night a member is priced by the sales ladder, the
     *                                                 target's own pace step is dropped and it goes to the upper edge
     */
    public function __construct(
        public readonly RoomSet $set,
        public readonly float $upperPercent,
        public readonly float $lowerPercent,
        public readonly float $referenceRoundingMargin = 0.0,
        public readonly float $lowerExtraAmount = 0.0,
        public readonly MemberPolicy $members = MemberPolicy::FreeOnly,
        public readonly EmptySetPolicy $onEmptySet = EmptySetPolicy::Skip,
        public readonly array $downOnlyNightTypes = [],
        public readonly bool $orderFloorYields = true,
        public readonly bool $followStrategyReference = true,
        public readonly WriteMode $writeMode = WriteMode::Pin,
    ) {
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown([
            'set', 'upper_percent', 'lower_percent', 'reference_rounding_margin', 'lower_extra_amount', 'members',
            'on_empty_set', 'down_only_night_types', 'order_floor_yields', 'follow_strategy_reference', 'write_mode',
        ]);
        $upper = $reader->number('upper_percent', null, 1.0, 100.0);
        $lower = $reader->number('lower_percent', null, 1.0, 100.0);
        if ($lower > $upper) {
            $reader->error('lower_percent', ValidationError::ORDER, 'must not be above the highest percent');
        }

        return new self(
            RoomSet::read($reader->group('set')),
            $upper,
            $lower,
            $reader->number('reference_rounding_margin', 0.0, 0.0, self::MAX_MARGIN),
            $reader->number('lower_extra_amount', 0.0, 0.0, self::MAX_EXTRA),
            $reader->choice('members', MemberPolicy::class, MemberPolicy::FreeOnly),
            $reader->choice('on_empty_set', EmptySetPolicy::class, EmptySetPolicy::Skip),
            $reader->choices('down_only_night_types', NightType::class),
            $reader->flag('order_floor_yields', true),
            $reader->flag('follow_strategy_reference', true),
            $reader->choice('write_mode', WriteMode::class, WriteMode::Pin),
        );
    }

    /**
     * The upper edge as a fraction of the reference (0.9 = 90%).
     */
    public function upper(): float
    {
        return $this->upperPercent / 100;
    }

    /**
     * The lower edge as a fraction of the upper edge (0.95 for 85.5% under 90%).
     */
    public function lowerRatio(): float
    {
        return $this->lowerPercent / $this->upperPercent;
    }

    public function isDownOnly(NightType $type): bool
    {
        return in_array($type, $this->downOnlyNightTypes, true);
    }

    public function toArray(): array
    {
        return [
            'set' => $this->set->toArray(),
            'upper_percent' => $this->upperPercent,
            'lower_percent' => $this->lowerPercent,
            'reference_rounding_margin' => $this->referenceRoundingMargin,
            'lower_extra_amount' => $this->lowerExtraAmount,
            'members' => $this->members->value,
            'on_empty_set' => $this->onEmptySet->value,
            'down_only_night_types' => array_map(static fn (NightType $type): string => $type->value, $this->downOnlyNightTypes),
            'order_floor_yields' => $this->orderFloorYields,
            'follow_strategy_reference' => $this->followStrategyReference,
            'write_mode' => $this->writeMode->value,
        ];
    }
}
