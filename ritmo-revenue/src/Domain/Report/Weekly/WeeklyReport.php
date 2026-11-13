<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Weekly;

use DateTimeImmutable;
use Ritmo\Domain\Report\Month\MonthStays;

/**
 * The five tables of the weekly panel.
 */
final class WeeklyReport
{
    /**
     * @param list<MadeWeek>                           $made     per week and property, oldest first
     * @param list<StayWeek>                           $stays    per stay week and property
     * @param array<string, array<string, MonthStays>> $goals    month => property id => stays of the month
     * @param list<ShareRow>                           $channels
     * @param list<ShareRow>                           $rooms
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly DateTimeImmutable $start,
        public readonly array $made,
        public readonly array $stays,
        public readonly array $goals,
        public readonly array $channels,
        public readonly array $rooms,
    ) {
    }
}
