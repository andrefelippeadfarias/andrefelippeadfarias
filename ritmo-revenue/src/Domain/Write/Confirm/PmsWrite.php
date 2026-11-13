<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;

/**
 * One range of a PMS calendar write: the same fields on consecutive nights of one room.
 */
final class PmsWrite
{
    public readonly DateTimeImmutable $from;

    public readonly DateTimeImmutable $to;

    /**
     * @param DateTimeImmutable $to last night of the range (inclusive)
     *
     * @throws InvalidArgumentException on fields of another system, no field, or a range that ends before it starts
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        public readonly NightFields $fields,
        public readonly ?int $expectedAvailable = null,
    ) {
        if ($fields->isEmpty() || !$fields->of(Provider::Pms)->equals($fields)) {
            throw new InvalidArgumentException('A PMS write sends at least one PMS field and nothing else.');
        }
        $this->from = Days::normalize($from);
        $this->to = Days::normalize($to);
        if ($this->to < $this->from) {
            throw new InvalidArgumentException('A range cannot end before it starts.');
        }
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function nights(): array
    {
        return iterator_to_array(new DateRange($this->from, $this->to), false);
    }
}
