<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\Assembly\AssembledPlan;
use Ritmo\Domain\Plan\Assembly\NightLine;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Support\Days;

/**
 * The assembled plan of a run as the plain arrays the next stages carry: every item and deletion
 * with its outcome (would write, no change, rejected), percentages and issues; the issues of the
 * plan as a whole; the payload and slices of what passes; the count of the nights grid by reason.
 */
final class AssemblyState
{
    private function __construct()
    {
    }

    /**
     * @param array<string, array{code: string, params: array<string, scalar|null>}> $priceTraces room-night key => trace entry
     * @param list<RoomType>                                                         $rooms
     *
     * @return array<string, mixed>
     */
    public static function of(AssembledPlan $run, array $priceTraces, array $rooms): array
    {
        $issues = self::issues($run->validation);
        $percent = $run->finalValidation?->plan === null ? null : (new PercentRounding())->apply($run->finalValidation->plan, $rooms);
        $converted = [];
        foreach ([PlanItemStatus::WOULD_WRITE => $percent?->items ?? [], PlanItemStatus::NO_CHANGE => $percent?->dropped ?? []] as $status => $list) {
            foreach ($list as $item) {
                $converted[$item->item->key()] = ['status' => $status, 'min_percent' => $item->minPercent, 'max_percent' => $item->maxPercent];
            }
        }

        $items = [];
        foreach ($run->plan->items as $item) {
            $key = $item->key();
            $step = $item->reasonParams['step'] ?? null;
            $items[$key] = [
                'item' => $item->toArray(),
                'trace' => isset($priceTraces[$key]) ? [$priceTraces[$key], ...($item->reasonParams['rule_trace'] ?? [])] : ($item->reasonParams['rule_trace'] ?? []),
                'pace_status' => is_array($step) ? ($step['source_status'] ?? null) : null,
                'direction' => is_array($step) ? ($step['direction'] ?? null) : null,
                'outcome' => ($converted[$key] ?? ['status' => PlanItemStatus::REJECTED, 'min_percent' => null, 'max_percent' => null]) + ['issues' => $issues['item'][$key] ?? []],
            ];
        }
        $written = $run->finalValidation?->plan?->plan;
        $kept = $written === null ? [] : array_fill_keys(array_map(static fn ($d): string => $d->key(), $written->deletions), true);
        $deletions = [];
        foreach ($run->plan->deletions as $deletion) {
            $deletions[] = $deletion->toArray() + [
                'status' => isset($kept[$deletion->key()]) ? PlanItemStatus::WOULD_WRITE : PlanItemStatus::REJECTED,
                'issues' => $issues['deletion'][$deletion->key()] ?? [],
            ];
        }

        return [
            'items' => $items,
            'deletions' => $deletions,
            'plan_issues' => $issues['plan'],
            'refused' => $run->finalValidation === null ? $run->validation->errorCodes() : null,
            'payload' => $run->payload?->toArray(),
            'slices' => array_map(static fn ($slice): array => $slice->toArray(), $run->slices),
            'lines' => self::lineCounts($run->lines),
        ];
    }

    /**
     * @return array{item: array<string, list<array<string, string>>>, deletion: array<string, list<array<string, string>>>, plan: list<array<string, string|null>>}
     */
    private static function issues(ValidationResult $result): array
    {
        $out = ['item' => [], 'deletion' => [], 'plan' => []];
        foreach ([...$result->errors, ...$result->warnings] as $issue) {
            /** @var PlanIssue $issue */
            $entry = ['severity' => $issue->severity, 'code' => $issue->code, 'message' => $issue->message];
            $key = $issue->roomCode === null || $issue->date === null ? null : $issue->roomCode . '|' . Days::key($issue->date);
            if ($issue->subject === IssueSubject::Item && $key !== null) {
                $out['item'][$key][] = $entry;
            } elseif ($issue->subject === IssueSubject::Deletion && $key !== null) {
                $out['deletion'][$key][] = $entry;
            } else {
                $out['plan'][] = $entry + ['room' => $issue->roomCode, 'night' => $issue->date === null ? null : Days::key($issue->date)];
            }
        }

        return $out;
    }

    /**
     * @param list<NightLine> $lines
     *
     * @return array<string, int> code => room nights
     */
    private static function lineCounts(array $lines): array
    {
        $counts = array_count_values(array_map(static fn (NightLine $line): string => $line->code, $lines));
        ksort($counts);

        return $counts;
    }
}
