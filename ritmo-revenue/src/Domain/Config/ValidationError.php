<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

/**
 * One problem found in a configuration, with the path of the value it is about.
 */
final class ValidationError
{
    public const REQUIRED = 'required';
    public const UNKNOWN = 'unknown';
    public const TYPE = 'type';
    public const RANGE = 'range';
    public const CHOICE = 'choice';
    public const FORMAT = 'format';
    public const UNIQUE = 'unique';
    public const LOCKED = 'locked';
    public const ORDER = 'order';
    public const REFERENCE = 'reference';

    /**
     * @param string $path    dotted path of the value ("pace.curves.weekday.7", "calendar.holidays.2.end")
     * @param string $code    one of the constants of this class
     * @param string $message English sentence without the path ("must be between 0 and 100")
     */
    public function __construct(
        public readonly string $path,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public function __toString(): string
    {
        return $this->path . ': ' . $this->message;
    }
}
