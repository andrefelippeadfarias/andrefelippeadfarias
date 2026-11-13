<?php

declare(strict_types=1);

namespace Ritmo\Application\Report;

use DateTimeImmutable;
use Ritmo\Application\Report\Store\RevenueTargetStore;
use Ritmo\Application\Report\Store\SnapshotStore;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Report\BookedUnits;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Booking\BookingLines;
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Domain\Report\Month\MonthPerformance;
use Ritmo\Domain\Report\Month\MonthReport;
use Ritmo\Domain\Report\Month\MonthTarget;
use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Report\Occupancy\MapNight;
use Ritmo\Domain\Report\Occupancy\OccupancyMap;
use Ritmo\Domain\Report\Occupancy\OccupancyRow;
use Ritmo\Domain\Report\Occupancy\OccupancyTable;
use Ritmo\Domain\Report\Review\ReviewDue;
use Ritmo\Domain\Report\Review\ReviewResult;
use Ritmo\Domain\Report\Review\ReviewSnapshot;
use Ritmo\Domain\Report\Review\TwelveMonthReview;
use Ritmo\Domain\Report\Targets\MonthForecast;
use Ritmo\Domain\Report\Targets\PropertyTarget;
use Ritmo\Domain\Report\Targets\RevenueForecast;
use Ritmo\Domain\Report\Targets\RevenueTargets;
use Ritmo\Domain\Report\Weekly\WeeklyPanel;
use Ritmo\Domain\Report\Weekly\WeeklyReport;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;

/**
 * Reads what the reports need for one property and calls the pure reports of Domain\Report.
 * Bookings come from the stored ones whose stay overlaps a window around today (stored bookings
 * are only those the runs read, by id and channel; no guest field exists).
 */
final class ReportService
{
    /** Days of stays read before today and after it (covers the review's 13 months and the panel). */
    private const BACK_DAYS = 400;
    private const AHEAD_DAYS = 400;

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly InventorySnapshotRepository $inventory,
        private readonly RevenueTargetStore $targets,
        private readonly SnapshotStore $snapshots,
        private readonly RuleSet $rules,
        private readonly ReportsConfig $config,
    ) {
    }

    /**
     * @return list<BookingLine>
     */
    public function lines(ReportScope $scope, DateTimeImmutable $today): array
    {
        $records = $this->bookings->overlapping(Days::add($today, -self::BACK_DAYS), Days::add($today, self::AHEAD_DAYS));

        return BookingLines::ofProperty(BookingLines::of($records, $scope->roomProperty(), $scope->property->timezone), $scope->property->id);
    }

    /**
     * @return array{rows: list<OccupancyRow>, map: list<MapNight>}
     */
    public function occupancy(ReportScope $scope, DateTimeImmutable $today): array
    {
        $goals = $this->rules->occupancyGoalsFor($scope->property);
        $last = Days::add($today, max(max(array_keys($goals) ?: [1]), $this->config->mapNights) - 1);
        $inventory = [];
        foreach ($scope->rooms as $room) {
            $inventory[$room->code] = array_values($this->inventory->latest($room->code, $today, $last));
        }
        $records = $this->bookings->overlapping($today, $last);
        $booked = BookedUnits::byRoomNight($records, new DateRange($today, $last));
        $holidays = [];
        for ($night = $today; $night <= Days::add($today, $this->config->mapNights - 1); $night = Days::add($night, 1)) {
            $holiday = $this->rules->holidayOn($night);
            if ($holiday !== null) {
                $holidays[Days::key($night)] = $holiday->name;
            }
        }

        return [
            'rows' => OccupancyTable::of($scope->rooms, $inventory, $booked, $today, [$scope->property->id => $goals], $goals, $this->config->groups),
            'map' => OccupancyMap::of($today, $this->config->mapNights, $scope->rooms, $inventory, $booked, [], $holidays),
        ];
    }

    public function month(ReportScope $scope, string $month, DateTimeImmutable $asOf): MonthReport
    {
        $today = Days::localDate($asOf, $scope->property->timezone);

        return MonthPerformance::of($this->lines($scope, $today), $month, $asOf, $scope->capacities(), $this->targetsOf($scope, [$month])[$month] ?? [], $scope->property->timezone);
    }

    public function weekly(ReportScope $scope, DateTimeImmutable $asOf): WeeklyReport
    {
        $today = Days::localDate($asOf, $scope->property->timezone);

        return WeeklyPanel::of($this->lines($scope, $today), $asOf, $scope->capacities(), $this->targetsOf($scope, Period::following($today, WeeklyPanel::GOAL_MONTHS)), $scope->property->timezone, $this->config->weeklyStart);
    }

    public function review(ReportScope $scope, DateTimeImmutable $asOf): ReviewResult
    {
        $today = Days::localDate($asOf, $scope->property->timezone);
        $months = Period::following($today, TwelveMonthReview::MONTHS);
        $rows = $this->targets->forProperty($scope->property->id, $months);
        $market = [];
        foreach ($rows as $month => $row) {
            $value = $row->market();
            if ($value !== null) {
                $market[$month] = $value;
            }
        }

        return TwelveMonthReview::of($this->lines($scope, $today), $today, $asOf, $scope->capacities(), $this->targetsOf($scope, $months), $market, [], $this->snapshots->latestBefore($scope->property->id, $today), $this->config->review, $scope->property->timezone);
    }

    public function reviewDue(ReportScope $scope, DateTimeImmutable $today): ReviewDue
    {
        return ReviewDue::of($today, $this->snapshots->lastTaken($scope->property->id), $this->config->review->intervalDays);
    }

    public function saveReview(ReportScope $scope, DateTimeImmutable $asOf, int $userId): ReviewSnapshot
    {
        $snapshot = ReviewSnapshot::of($this->review($scope, $asOf));
        $this->snapshots->save($scope->property->id, $snapshot, $userId, $asOf);

        return $snapshot;
    }

    /**
     * The 12 months from the current one: the site forecast per scenario and the per-property targets.
     *
     * @return array{forecast: list<MonthForecast>, targets: list<PropertyTarget>, months: list<string>}
     */
    public function targets(ReportScope $scope, DateTimeImmutable $asOf): array
    {
        $today = Days::localDate($asOf, $scope->property->timezone);
        $months = Period::following($today, 12);
        $rows = $this->targets->forProperty($scope->property->id, $months);
        $inputs = $onBooks = [];
        $lines = $this->lines($scope, $today);
        foreach ($months as $month) {
            if (isset($rows[$month])) {
                $inputs[$month][$scope->property->id] = $rows[$month]->inputs();
            }
            $stays = MonthPerformance::of($lines, $month, $asOf, $scope->capacities(), [], $scope->property->timezone)->stays;
            $onBooks[$month] = $stays[$scope->property->id]->revenue;
        }
        $units = $scope->capacities();

        return [
            'months' => $months,
            'forecast' => RevenueForecast::months($months, $units, $inputs, $onBooks, $this->config->forecast),
            'targets' => RevenueTargets::of($months, $units, $inputs, $this->config->forecast),
        ];
    }

    /**
     * @param list<string> $months
     *
     * @return array<string, array<string, MonthTarget>> month => property id => targets
     */
    private function targetsOf(ReportScope $scope, array $months): array
    {
        $out = [];
        foreach ($this->targets->forProperty($scope->property->id, $months) as $month => $row) {
            $out[$month][$scope->property->id] = $row->target();
        }

        return $out;
    }
}
