<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

/**
 * Which room nights of the grid stay visible ("only=" of the Nights screen). The other nights
 * of the window are blank, so the grid keeps its columns.
 */
final class NightFilter
{
    public const ALL = 'all';
    public const WOULD_CHANGE = 'would_change';
    public const REFUSED = 'refused';
    public const FROZEN = 'frozen';
    public const WITH_ORDER = 'with_order';
    public const WITH_ALERT = 'with_alert';
    public const STATUS_PREFIX = 'status:';

    public const SIMPLE = [self::ALL, self::WOULD_CHANGE, self::REFUSED, self::FROZEN, self::WITH_ORDER, self::WITH_ALERT];

    public const STATUSES = ['ahead', 'on_pace', 'behind', 'very_behind', 'floor'];

    private function __construct(public readonly string $only)
    {
    }

    /**
     * A filter from its URL value; anything unknown shows every night.
     */
    public static function from(string $only): self
    {
        if (in_array($only, self::SIMPLE, true)) {
            return new self($only);
        }
        if (str_starts_with($only, self::STATUS_PREFIX) && in_array(substr($only, strlen(self::STATUS_PREFIX)), self::STATUSES, true)) {
            return new self($only);
        }

        return new self(self::ALL);
    }

    /** @return list<string> every value the filter accepts */
    public static function values(): array
    {
        return array_merge(self::SIMPLE, array_map(static fn (string $s): string => self::STATUS_PREFIX . $s, self::STATUSES));
    }

    public function accepts(NightCell $cell): bool
    {
        return match ($this->only) {
            self::ALL => true,
            self::WOULD_CHANGE => $cell->planned->status === PlannedNight::WOULD_WRITE,
            self::REFUSED => $cell->planned->status === PlannedNight::REJECTED,
            self::FROZEN => $cell->frozen,
            self::WITH_ORDER => $cell->orderIds !== [],
            self::WITH_ALERT => $cell->alertCount > 0,
            default => $cell->planned->status !== null && $cell->planned->paceCategory() === substr($this->only, strlen(self::STATUS_PREFIX)),
        };
    }
}
