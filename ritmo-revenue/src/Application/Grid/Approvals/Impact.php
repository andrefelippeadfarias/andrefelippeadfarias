<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * The summary line of a request or of a selection: how many nights go up and down (with the
 * median change and the largest rise), how many are tonight or tomorrow, on holidays, deletions
 * followed by their rewrite, and listing changes. Pure.
 */
final class Impact
{
    /**
     * @param array{amount: float, night: DateTimeImmutable}|null $largestRise
     */
    private function __construct(
        public readonly int $nights,
        public readonly int $up,
        public readonly ?float $medianUp,
        public readonly int $down,
        public readonly ?float $medianDown,
        public readonly ?array $largestRise,
        public readonly int $soon,
        public readonly int $holidays,
        public readonly int $rewrites,
        public readonly int $listing,
    ) {
    }

    /**
     * @param list<EntryRow> $rows
     */
    public static function of(array $rows): self
    {
        $nights = [];
        $up = [];
        $down = [];
        $largest = null;
        $soon = [];
        $holidays = [];
        $rewrites = 0;
        $listing = 0;
        foreach ($rows as $row) {
            if ($row->night === null) {
                ++$listing;
                continue;
            }
            $key = $row->room . '|' . Days::key($row->night);
            $nights[$key] = true;
            $change = $row->changePercent();
            if ($row->direction === EntryRow::UP && $change !== null) {
                $up[] = $change;
                $rise = (float) $row->after() - (float) $row->before();
                if ($largest === null || $rise > $largest['amount']) {
                    $largest = ['amount' => $rise, 'night' => $row->night];
                }
            } elseif ($row->direction === EntryRow::DOWN && $change !== null) {
                $down[] = $change;
            }
            if (array_intersect([EntryWarnings::TONIGHT, EntryWarnings::TOMORROW], $row->warnings) !== []) {
                $soon[$key] = true;
            }
            if (in_array(EntryWarnings::HOLIDAY, $row->warnings, true)) {
                $holidays[$key] = true;
            }
            $rewrites += $row->kind === 'deletion' ? 1 : 0;
        }

        return new self(count($nights), count($up), Segment::median($up), count($down), Segment::median($down), $largest, count($soon), count($holidays), $rewrites, $listing);
    }
}
