<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use InvalidArgumentException;

/**
 * A wall-clock time of a property ("07:30"), without a date or a timezone.
 */
final class LocalTime
{
    public const PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    private function __construct(public readonly int $hour, public readonly int $minute)
    {
    }

    /**
     * @throws InvalidArgumentException when the text is not "HH:MM" on a 24-hour clock
     */
    public static function fromString(string $text): self
    {
        if (preg_match(self::PATTERN, $text) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a time of day as HH:MM.', $text));
        }

        return new self((int) substr($text, 0, 2), (int) substr($text, 3, 2));
    }

    /**
     * Minutes since midnight.
     */
    public function minutes(): int
    {
        return $this->hour * 60 + $this->minute;
    }

    public function toString(): string
    {
        return sprintf('%02d:%02d', $this->hour, $this->minute);
    }
}
