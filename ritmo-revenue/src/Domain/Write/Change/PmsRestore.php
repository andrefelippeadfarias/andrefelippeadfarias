<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;

/**
 * The PMS fields of one room night an undo writes back (stay, restriction, availability or a
 * channel limit the plugin had written; never a price, never a booking).
 */
final class PmsRestore
{
    public readonly DateTimeImmutable $night;

    /**
     * @param list<int> $undoOf the change records it undoes
     *
     * @throws InvalidArgumentException on fields of another system or none
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly NightFields $fields,
        public readonly array $undoOf = [],
    ) {
        if ($fields->isEmpty() || !$fields->of(Provider::Pms)->equals($fields)) {
            throw new InvalidArgumentException('A PMS restore writes PMS fields only.');
        }
        $this->night = Days::normalize($night);
    }
}
