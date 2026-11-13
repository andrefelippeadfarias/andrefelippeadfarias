<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * One occurrence of a slot: a kind at a local date and time of the property, and the UTC instant
 * it falls on. It is identified by its local date-time and kind ({@see key()}), so a local time
 * that repeats when the clocks go back is still one slot.
 */
final class Slot
{
    public readonly DateTimeImmutable $localDate;

    public readonly DateTimeImmutable $at;

    /**
     * @param DateTimeImmutable $localDate date-only (the property's local date)
     * @param DateTimeImmutable $at        the instant, any timezone; kept in UTC
     */
    public function __construct(
        public readonly SlotKind $kind,
        DateTimeImmutable $localDate,
        public readonly LocalTime $time,
        DateTimeImmutable $at,
    ) {
        $this->localDate = Days::normalize($localDate);
        $this->at = $at->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The local date-time of the slot as "YYYY-MM-DDTHH:MM".
     */
    public function localKey(): string
    {
        return Days::key($this->localDate) . 'T' . $this->time->toString();
    }

    /**
     * Unique per property: the local date-time and the kind.
     */
    public function key(): string
    {
        return $this->localKey() . '|' . $this->kind->value;
    }

    /**
     * Seconds from the slot's instant to $instant (negative before the slot).
     */
    public function secondsUntil(DateTimeImmutable $instant): int
    {
        return $instant->getTimestamp() - $this->at->getTimestamp();
    }

    /**
     * Parses a local key back ("YYYY-MM-DDTHH:MM").
     *
     * @return array{0: DateTimeImmutable, 1: LocalTime}
     *
     * @throws InvalidArgumentException on another format
     */
    public static function parseLocalKey(string $localKey): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/', $localKey, $m) !== 1 || !Days::isValid($m[1])) {
            throw new InvalidArgumentException(sprintf('"%s" is not a local slot key.', $localKey));
        }

        return [Days::date($m[1]), LocalTime::fromString($m[2])];
    }
}
