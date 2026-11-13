<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;

/**
 * The instant a movement read starts from: the cursor (the instant before the last successful
 * read) minus the overlap margin; without a cursor, the first read of the property (a baseline)
 * over the initial look-back. After a long stop the read is capped and the uncovered interval is
 * the vigilance gap.
 *
 * Reference: SKILL.md "--desde <início da rodada anterior - 5 min>", beds24.ler_modificadas.
 */
final class MovementWindow
{
    private function __construct(
        public readonly DateTimeImmutable $since,
        public readonly bool $baseline,
        public readonly ?DateTimeImmutable $gapFrom,
    ) {
    }

    public static function compute(?DateTimeImmutable $cursor, DateTimeImmutable $now, LightRunConfig $config, int $maxLookbackDays): self
    {
        if ($cursor === null) {
            return new self($now->modify(sprintf('-%d hours', $config->initialLookbackHours)), true, null);
        }
        $since = $cursor->modify(sprintf('-%d minutes', $config->movementOverlapMinutes));
        $floor = $now->modify(sprintf('-%d days', $maxLookbackDays));

        return $since < $floor ? new self($floor, false, $since) : new self($since, false, null);
    }

    /**
     * True when bookings changed between gapFrom and since were never read.
     */
    public function hasGap(): bool
    {
        return $this->gapFrom !== null;
    }
}
