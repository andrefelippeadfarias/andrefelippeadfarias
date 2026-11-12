<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use DateTimeImmutable;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * The nights a relation applies to. Every empty or null criterion matches every night.
 */
final class RelationScope
{
    public const MAX_DAYS_OUT = 730;
    private const MAX_PERIODS = 50;

    /**
     * @param list<NightType> $nightTypes
     * @param list<int>       $weekdays       0 is the Monday night ... 6 the Sunday night
     * @param list<DateRange> $periods        first and last night; empty = always
     * @param list<DateRange> $excludePeriods
     * @param string|null     $requiresOrder  id of an owner order that must be active for the target
     */
    public function __construct(
        public readonly array $nightTypes = [],
        public readonly array $weekdays = [],
        public readonly array $periods = [],
        public readonly array $excludePeriods = [],
        public readonly ?int $minDaysOut = null,
        public readonly ?int $maxDaysOut = null,
        public readonly ?string $requiresOrder = null,
    ) {
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown(['night_types', 'weekdays', 'periods', 'exclude_periods', 'min_days_out', 'max_days_out', 'requires_order']);
        $weekdays = [];
        foreach ($reader->list('weekdays') as $index => $day) {
            if (!is_int($day) || $day < 0 || $day > 6) {
                $reader->error('weekdays.' . $index, ValidationError::RANGE, 'must be a day between 0 and 6');
                continue;
            }
            $weekdays[] = $day;
        }
        $min = $reader->optionalInteger('min_days_out', 0, self::MAX_DAYS_OUT);
        $max = $reader->optionalInteger('max_days_out', 0, self::MAX_DAYS_OUT);
        if ($min !== null && $max !== null && $min > $max) {
            $reader->error('max_days_out', ValidationError::ORDER, 'must not be below the minimum days out');
        }

        return new self(
            $reader->choices('night_types', NightType::class),
            array_values(array_unique($weekdays)),
            self::periods($reader, 'periods'),
            self::periods($reader, 'exclude_periods'),
            $min,
            $max,
            $reader->optionalText('requires_order', Relation::REF_PATTERN),
        );
    }

    /**
     * True when the night is inside every criterion except the order, which needs the owner's
     * orders and is checked by the stage that runs the relation.
     */
    public function matches(DateTimeImmutable $night, NightType $type, DateTimeImmutable $today): bool
    {
        if ($this->nightTypes !== [] && !in_array($type, $this->nightTypes, true)) {
            return false;
        }
        if ($this->weekdays !== [] && !in_array(Days::weekday($night), $this->weekdays, true)) {
            return false;
        }
        if ($this->periods !== [] && !self::inAny($this->periods, $night)) {
            return false;
        }
        if (self::inAny($this->excludePeriods, $night)) {
            return false;
        }
        $daysOut = Days::diff($today, $night);

        return ($this->minDaysOut === null || $daysOut >= $this->minDaysOut)
            && ($this->maxDaysOut === null || $daysOut <= $this->maxDaysOut);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $periods = static fn (array $ranges): array => array_map(
            static fn (DateRange $range): array => ['start' => Days::key($range->first()), 'end' => Days::key($range->last())],
            $ranges
        );

        return [
            'night_types' => array_map(static fn (NightType $type): string => $type->value, $this->nightTypes),
            'weekdays' => $this->weekdays,
            'periods' => $periods($this->periods),
            'exclude_periods' => $periods($this->excludePeriods),
            'min_days_out' => $this->minDaysOut,
            'max_days_out' => $this->maxDaysOut,
            'requires_order' => $this->requiresOrder,
        ];
    }

    /**
     * @param list<DateRange> $ranges
     */
    private static function inAny(array $ranges, DateTimeImmutable $night): bool
    {
        foreach ($ranges as $range) {
            if ($range->contains($night)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DateRange>
     */
    private static function periods(Reader $reader, string $key): array
    {
        $ranges = [];
        $items = $reader->list($key);
        if (count($items) > self::MAX_PERIODS) {
            $reader->error($key, ValidationError::RANGE, sprintf('must have at most %d items', self::MAX_PERIODS));
        }
        foreach ($items as $index => $item) {
            $period = $reader->item($key, $index, $item);
            $period->rejectUnknown(['start', 'end']);
            $start = $period->date('start');
            $end = $period->date('end');
            if ($start === null || $end === null) {
                continue;
            }
            if ($start > $end) {
                $period->error('end', ValidationError::ORDER, 'must not be before the start');
                continue;
            }
            $ranges[] = new DateRange($start, $end);
        }

        return $ranges;
    }
}
