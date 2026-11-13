<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Journal;

use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteOperation;

/**
 * The stored state of the nights a run touches, as read from each system (NightRule forms), and
 * what each step leaves there:
 * - a write merges its fields into the stored rule (fields not sent are kept);
 * - a deletion leaves no rule;
 * - the rewrite of a group (after its deletion) leaves only its own fields;
 * - a PMS write sets only the fields sent; availability is read back net of bookings, so it is
 *   compared with the expected net value when the change carries it, else with the value written
 *   (reference: beds24._conferir, num_avail_esperado).
 */
final class StoredState
{
    private const RULE_FIELDS = ['min_price', 'max_price', 'min_stay', 'check_in', 'check_out', 'fixed'];

    /**
     * @param array<string, array<string, array<string, mixed>|null>> $pricing listing id => night => rule (null: none)
     * @param array<string, array<string, array<string, mixed>>>      $pms     PMS room id => night => values
     */
    public function __construct(public readonly array $pricing = [], public readonly array $pms = [])
    {
    }

    /**
     * The state before the step, target => night => state.
     *
     * @return array<string, array<string, array<string, mixed>|null>>
     */
    public function before(WriteStep $step): array
    {
        $out = [];
        foreach ($step->nights as $target => $nights) {
            foreach ($nights as $night) {
                $out[$target][$night] = $step->operation === WriteOperation::PmsCalendar
                    ? ($this->pms[$target][$night] ?? null)
                    : ($this->pricing[$target][$night] ?? null);
            }
        }

        return $out;
    }

    /**
     * What the step leaves, target => night => {state, fields compared}.
     *
     * @return array<string, array<string, array{state: array<string, mixed>|null, fields: list<string>}>>
     */
    public function intended(WriteStep $step): array
    {
        return match ($step->operation) {
            WriteOperation::OverridesWrite => $this->afterWrite($step),
            WriteOperation::OverridesDelete => array_map(static fn (array $nights): array => array_fill_keys($nights, ['state' => null, 'fields' => []]), $step->nights),
            WriteOperation::PmsCalendar => $this->afterPms($step),
            default => [],
        };
    }

    /**
     * @return array<string, array<string, array{state: array<string, mixed>|null, fields: list<string>}>>
     */
    private function afterWrite(WriteStep $step): array
    {
        $target = $step->target();
        $out = [];
        foreach ($step->payload as $entry) {
            $sent = NightRule::sent($entry);
            $before = $step->isGroupRewrite() ? null : ($this->pricing[$target][$entry['date']] ?? null);
            $out[$target][$entry['date']] = [
                'state' => NightRule::merged($before, $sent),
                'fields' => $step->isGroupRewrite() ? self::RULE_FIELDS : array_keys($sent),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, array{state: array<string, mixed>|null, fields: list<string>}>>
     */
    private function afterPms(WriteStep $step): array
    {
        $out = [];
        foreach ($step->changes as $change) {
            $sent = self::pmsSent($change);
            foreach ($change->nights() as $night) {
                $out[$change->roomId][$night] = [
                    'state' => array_merge($this->pms[$change->roomId][$night] ?? [], $sent),
                    'fields' => array_keys($sent),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, int|string>
     */
    private static function pmsSent(CalendarChange $change): array
    {
        $range = $change->range();
        $sent = array_intersect_key($range, array_flip(['minStay', 'maxStay', 'override']));
        if ($change->available !== null) {
            $sent['numAvail'] = $change->expectedAvailable ?? $change->available;
        }

        return $sent;
    }
}
