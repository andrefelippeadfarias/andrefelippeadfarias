<?php

declare(strict_types=1);

namespace Ritmo\Rest\Reports;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Admin\Reports\ReportTable;
use Ritmo\Admin\Reports\ReportViews;
use Ritmo\Admin\Reports\RunLineText;
use Ritmo\Application\Report\ReportScope;
use Ritmo\Application\Report\ReportService;
use Ritmo\Application\Report\Store\RevenueTargetStore;
use Ritmo\Application\Report\Store\TargetRow;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Report\RunLines\RunLine;
use Ritmo\Domain\Support\Days;

/**
 * The answers of the reports REST routes (spec 06, 2.9): each report as its summary sentence and
 * tables (caption, column labels, rows as a person reads them), the targets, the review snapshot
 * and a run's report lines with their text. Errors are sentences without internal data.
 */
final class ReportEndpoints
{
    /**
     * @param \Closure(string): ?Property             $property id => property
     * @param \Closure(Property): ReportScope         $scope
     * @param \Closure(int): ?array<string, mixed>    $run      run id => run row (with its summary and property)
     */
    public function __construct(
        private readonly ReportService $reports,
        private readonly RevenueTargetStore $targets,
        private readonly \Closure $property,
        private readonly \Closure $scope,
        private readonly \Closure $run,
        private readonly DateTimeImmutable $now,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function report(string $tab, string $propertyId, string $month = ''): array
    {
        $property = ($this->property)($propertyId);
        if ($property === null) {
            return self::notFound();
        }
        $scope = ($this->scope)($property);
        $names = [];
        foreach ($scope->rooms as $room) {
            $names[$room->code] = $room->name;
        }
        $today = Days::localDate($this->now, $property->timezone);
        $view = ReportViews::of($tab, $this->reports, $scope, $this->now, ReportViews::monthOf($month, $today), $names);

        return [200, ['summary' => $view['summary'], 'tables' => array_map(static fn (ReportTable $t): array => self::table($t, $property->currency), $view['tables'])]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function snapshot(string $propertyId, int $userId): array
    {
        $property = ($this->property)($propertyId);
        if ($property === null) {
            return self::notFound();
        }
        $snapshot = $this->reports->saveReview(($this->scope)($property), $this->now, $userId);

        return [200, ['saved' => Days::key($snapshot->day), 'text' => __('Review saved. The next review compares each month with this one.', 'ritmo-revenue')]];
    }

    /**
     * @param array<string, mixed> $fields field => typed value (number or empty)
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function saveTargets(string $propertyId, string $month, array $fields, int $userId): array
    {
        $property = ($this->property)($propertyId);
        if ($property === null) {
            return self::notFound();
        }
        $values = [];
        foreach (TargetRow::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            $values[$field] = $value === null || $value === '' ? null : (is_numeric($value) ? (float) $value : false);
        }
        try {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || in_array(false, $values, true)) {
                throw new InvalidArgumentException('invalid');
            }
            /** @var array<string, float|null> $values */
            $this->targets->save($property->id, new TargetRow($month, $values), $userId, $this->now);
        } catch (InvalidArgumentException) {
            return [400, ['text' => __('The targets were not saved: use numbers, percentages from 0 to 100 and an occupancy cap above 0 and at most 1.', 'ritmo-revenue')]];
        }

        return [200, ['month' => $month, 'text' => __('Targets saved. They change what the reports compare with, never a price.', 'ritmo-revenue')]];
    }

    /**
     * A run's report lines: code, parameters and the text in the reader's language.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function runReport(int $runId): array
    {
        $run = ($this->run)($runId);
        if ($run === null) {
            return [404, ['text' => __('That run does not exist.', 'ritmo-revenue')]];
        }
        $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
        $items = $summary['report']['items'] ?? null;
        if (!is_array($items)) {
            $old = RunLineText::ofSummary($summary);

            return [200, ['lines' => array_map(static fn (string $text): array => ['code' => null, 'params' => [], 'text' => $text], $old['lines']), 'recorded' => $old['recorded']]];
        }
        $lines = [];
        foreach ($items as $item) {
            $line = RunLine::fromArray(is_array($item) ? $item : []);
            $lines[] = $line === null
                ? ['code' => null, 'params' => [], 'text' => RunLineText::render([])]
                : [...$line->toArray(), 'text' => RunLineText::of($line)];
        }

        return [200, ['lines' => $lines, 'recorded' => false]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(ReportTable $table, string $currency): array
    {
        return [
            'id' => $table->id,
            'caption' => $table->caption,
            'columns' => array_map(static fn (array $c): string => $c[0], $table->columns),
            'rows' => array_map(static fn (array $row): array => $table->formatted($row, $currency), $table->rows),
            'totals' => $table->totals === null ? null : $table->formatted($table->totals, $currency),
        ];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function notFound(): array
    {
        return [404, ['text' => __('That property does not exist.', 'ritmo-revenue')]];
    }
}
