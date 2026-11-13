<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Resume;

use Ritmo\Application\Write\Journal\NightRule;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Journal\StoredState;
use Ritmo\Contracts\WriteOperation;

/**
 * Classifies the unsettled journal rows of earlier runs against the state read again now, never
 * against the old payload alone: each night is applied (holds what the step intended), not
 * applied (still what was read before) or diverged. A row is stored when every night is applied,
 * abandoned when none is (or it was never sent) and a mismatch otherwise. A group whose deletion
 * is applied and whose rewrite is not is a broken group, repaired first; the rewrite of a group is
 * read even when it was never sent, so a group whose repair is in force closes. Pure.
 */
final class ResumePlanner
{
    private const RULE_FIELDS = ['min_price', 'max_price', 'min_stay', 'check_in', 'check_out', 'fixed'];

    private const PMS_FIELDS = ['minStay', 'maxStay', 'override', 'numAvail'];

    /** Statuses of a call that was never sent, or refused whole: nothing of it can be stored. */
    private const NEVER_APPLIED = [OpStatus::Planned, OpStatus::Abandoned, OpStatus::Rejected, OpStatus::NotReady, OpStatus::RateLimited, OpStatus::Skipped, OpStatus::Quarantined];

    /**
     * @param list<array<string, mixed>> $rows journal rows (WriteOpRepository::unsettled())
     */
    public function classify(array $rows, StoredState $fresh): ResumeReport
    {
        $rewrites = [];
        foreach ($rows as $row) {
            if ($row['group_key'] !== '' && $row['operation'] === WriteOperation::OverridesWrite->value) {
                $rewrites[$row['group_key']] = $row['intended_state'];
            }
        }
        $statuses = $classes = $observed = [];
        foreach ($rows as $row) {
            [$statuses[$row['id']], $classes[$row['id']], $observed[$row['id']]] = $this->row($row, $fresh, $rewrites[$row['group_key']] ?? []);
        }

        return new ResumeReport($statuses, $classes, $this->brokenGroups($rows, $statuses), $rows, [], array_filter($observed, static fn (array $o): bool => $o !== []));
    }

    /**
     * Listings and PMS rooms with the nights to read again for these rows.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>} pricing, pms
     */
    public static function targets(array $rows): array
    {
        $pricing = $pms = [];
        foreach ($rows as $row) {
            foreach ($row['payload']['nights'] ?? [] as $target => $nights) {
                if ($row['operation'] === WriteOperation::PmsCalendar->value) {
                    $pms[$target] = array_values(array_unique([...($pms[$target] ?? []), ...$nights]));
                } elseif (in_array($row['operation'], [WriteOperation::OverridesWrite->value, WriteOperation::OverridesDelete->value], true)) {
                    $pricing[$target] = array_values(array_unique([...($pricing[$target] ?? []), ...$nights]));
                }
            }
        }

        return [$pricing, $pms];
    }

    /**
     * @param array<string, mixed>                                                                   $row
     * @param array<string, array<string, array{state: array<string, mixed>|null, fields: list<string>}>> $rewrite what the group's rewrite intended (a deletion of the group is applied when its night holds it)
     *
     * @return array{0: OpStatus, 1: array<string, array<string, string>>, 2: array<string, array<string, array<string, mixed>|null>>} status, night classes, state read now
     */
    private function row(array $row, StoredState $fresh, array $rewrite): array
    {
        $writes = [WriteOperation::OverridesWrite->value, WriteOperation::OverridesDelete->value, WriteOperation::PmsCalendar->value];
        $status = OpStatus::from((string) $row['status']);
        $groupRewrite = $row['group_key'] !== '' && $row['operation'] === WriteOperation::OverridesWrite->value;
        if ((in_array($status, self::NEVER_APPLIED, true) && !$groupRewrite) || !in_array($row['operation'], $writes, true)) {
            return [$status === OpStatus::Planned ? OpStatus::Abandoned : $status, [], []];
        }
        $pms = $row['operation'] === WriteOperation::PmsCalendar->value;
        $classes = $read = [];
        foreach ($row['intended_state'] as $target => $nights) {
            foreach ($nights as $night => $want) {
                $now = $pms ? ($fresh->pms[$target][$night] ?? null) : ($fresh->pricing[$target][$night] ?? null);
                $read[$target][$night] = $now;
                $before = $row['before_state'][$target][$night] ?? null;
                $class = $this->night($now, $want, $before, $pms);
                $after = $rewrite[$target][$night] ?? null;
                if ($class !== NightClass::Applied && $row['operation'] === WriteOperation::OverridesDelete->value && $after !== null && NightRule::holds($now, $after['state'], $after['fields'])) {
                    $class = NightClass::Applied;
                }
                $classes[$target][$night] = $class->value;
            }
        }
        $all = array_merge(...array_values(array_map('array_values', $classes)));

        return [match (true) {
            $all !== [] && array_unique($all) === [NightClass::Applied->value] => OpStatus::Stored,
            $all === [] || array_unique($all) === [NightClass::NotApplied->value] => OpStatus::Abandoned,
            default => OpStatus::Mismatch,
        }, $classes, $read];
    }

    /**
     * @param array<string, mixed>|null                                  $now
     * @param array{state: array<string, mixed>|null, fields: list<string>} $want
     * @param array<string, mixed>|null                                  $before
     */
    private function night(?array $now, array $want, ?array $before, bool $pms): NightClass
    {
        if (NightRule::holds($now, $want['state'], $want['fields'])) {
            return NightClass::Applied;
        }
        $fields = $want['fields'] === [] ? ($pms ? self::PMS_FIELDS : self::RULE_FIELDS) : $want['fields'];
        if ($before === null ? $now === null : NightRule::holds($now, $before, $fields)) {
            return NightClass::NotApplied;
        }

        return NightClass::Diverged;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, OpStatus>       $statuses
     *
     * @return list<BrokenGroup>
     */
    private function brokenGroups(array $rows, array $statuses): array
    {
        $deleted = $rewrites = [];
        foreach ($rows as $row) {
            $key = (string) $row['group_key'];
            if ($key === '') {
                continue;
            }
            if ($row['operation'] === WriteOperation::OverridesDelete->value && $statuses[$row['id']] === OpStatus::Stored) {
                $deleted[$key] = $row;
            } elseif ($row['operation'] === WriteOperation::OverridesWrite->value) {
                $rewrites[$key] = $row;
            }
        }
        $broken = [];
        foreach ($deleted as $key => $row) {
            $rewrite = $rewrites[$key] ?? null;
            if ($rewrite !== null && $statuses[$rewrite['id']] !== OpStatus::Stored) {
                $broken[] = new BrokenGroup($key, (string) $rewrite['payload']['room'], (string) $row['target_ref'], $rewrite['payload']['entries']);
            }
        }

        return $broken;
    }
}
