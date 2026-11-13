<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use DateTimeImmutable;

/**
 * The value of one series on one night and where it came from.
 */
final class NightValue
{
    /** Measured on stays of the same kind that start on the night. */
    public const NIGHT = 'night';

    /** Measured on stays of the same kind that include the night. */
    public const CONTAINING = 'containing';

    /** Measured on the nearest stays of the same kind (distanceDays away). */
    public const NEIGHBOUR = 'neighbour';

    /** The typical value of the kind of night (no stay near it). */
    public const TYPE_TABLE = 'type_table';

    /**
     * @param array<string, int> $values percentile ("25") => whole public price
     */
    public function __construct(
        public readonly array $values,
        public readonly string $origin,
        public readonly ?int $distanceDays,
        public readonly int $stays,
        public readonly int $competitors,
        public readonly ?DateTimeImmutable $oldestCapture,
        public readonly ?DateTimeImmutable $newestCapture,
    ) {
    }
}
