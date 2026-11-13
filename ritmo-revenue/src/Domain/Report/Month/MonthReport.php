<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Month;

use DateTimeImmutable;

/**
 * The month performance report: bookings made in the month (per property and for the site),
 * cancellations made in the month and the stays of the month per property.
 */
final class MonthReport
{
    /**
     * @param array<string, MadeInMonth>        $made          property id => made; the site under SITE
     * @param array<string, MonthCancellations> $cancellations property id => cancellations; the site under SITE
     * @param array<string, MonthStays>         $stays         property id => stays (no site total)
     */
    public function __construct(
        public readonly string $month,
        public readonly DateTimeImmutable $asOf,
        public readonly array $made,
        public readonly array $cancellations,
        public readonly array $stays,
    ) {
    }

    /** Key of the whole site in $made and $cancellations. */
    public const SITE = '';
}
