<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * What a strategy decided for one night: the quote of every room it prices, or a ruler gap (the
 * strategy would price the night but the ruler has no entry for it). Immutable.
 */
final class LadderNight
{
    public readonly DateTimeImmutable $night;

    /**
     * @param array<string, LadderRoomQuote> $quotes by room code, in the order they were priced
     */
    public function __construct(
        DateTimeImmutable $night,
        public readonly array $quotes = [],
        public readonly bool $rulerGap = false,
    ) {
        $this->night = Days::normalize($night);
    }

    public function quote(string $room): ?LadderRoomQuote
    {
        return $this->quotes[$room] ?? null;
    }

    public function with(LadderRoomQuote $quote): self
    {
        $quotes = $this->quotes;
        $quotes[$quote->roomCode] = $quote;

        return new self($this->night, $quotes, $this->rulerGap);
    }
}
