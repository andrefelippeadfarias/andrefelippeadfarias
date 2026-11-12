<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeInterface;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The market ruler of a period: one {@see RulerNight} per night. A night without an entry is a
 * ruler gap: the ladder does not price it.
 */
final class MarketRuler
{
    /** @var array<string, RulerNight> */
    private readonly array $nights;

    /**
     * @param list<RulerNight> $nights
     */
    public function __construct(array $nights = [])
    {
        $byKey = [];
        foreach ($nights as $night) {
            $byKey[Days::key($night->night)] = $night;
        }
        $this->nights = $byKey;
    }

    public function night(DateTimeInterface $night): ?RulerNight
    {
        return $this->nights[Days::key($night)] ?? null;
    }

    /**
     * @param array<string, array<string, mixed>> $nights night (YYYY-MM-DD) => row of RulerNight::fromArray()
     *
     * @throws InvalidArgumentException on a malformed night
     */
    public static function fromArray(array $nights): self
    {
        $list = [];
        foreach ($nights as $night => $row) {
            $list[] = RulerNight::fromArray((string) $night, $row);
        }

        return new self($list);
    }
}
