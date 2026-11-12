<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Plan\LastMinuteRule;
use Ritmo\Domain\Plan\PlanIssue;

/**
 * A floor under the listing minimum without an order, in a room and window the last-minute rule
 * covers: not deeper than its share, only with the fresh calendar, never on a sold-out night, not
 * on a night mostly sold (tonight only with a warning), and in "last unit only" rooms for the last
 * free unit.
 *
 * Reference: Regra I of validar_plano.Validador.validar_data.
 */
final class LastMinuteCheck
{
    public function __construct(private readonly LastMinuteRule $rule, private readonly IssueLog $log)
    {
    }

    public function check(ItemFacts $f, float $floor): void
    {
        $limit = $f->room->min * (1 - $this->rule->maxDiscount);
        if ($floor < $limit) {
            $this->log->item($f, true, PlanIssue::LAST_MINUTE_TOO_DEEP, sprintf('The floor of %.2f is under the deepest last-minute discount, %.2f.', $floor, $limit), ['amount' => $floor, 'limit' => $limit]);

            return;
        }
        $night = $f->fresh;
        if ($night === null) {
            $this->log->item($f, true, PlanIssue::LAST_MINUTE_WITHOUT_CALENDAR, 'A last-minute discount needs the units sold and free of the night in the fresh calendar.');

            return;
        }
        $free = $night->free();
        $share = $night->total > 0 ? $night->sold / $night->total : 0.0;
        $params = ['sold' => $night->sold, 'total' => $night->total];
        if ($free <= 0) {
            $this->log->item($f, true, PlanIssue::LAST_MINUTE_SOLD_OUT, 'A last-minute discount on a sold-out night: there is no unit to discount.', $params);
        } elseif ($share > $this->rule->maxSoldShare && $f->daysOut >= 1) {
            $this->log->item($f, true, PlanIssue::LAST_MINUTE_TOO_SOLD, sprintf('A last-minute discount does not start on a night with more than %s%% sold.', $this->rule->maxSoldShare * 100), $params);
        } elseif ($share > $this->rule->maxSoldShare) {
            $this->log->item($f, false, PlanIssue::LAST_MINUTE_TOO_SOLD_TONIGHT, 'Tonight is mostly sold: the discount only continues the one in force.', $params);
        }
        if (in_array($f->room->code, $this->rule->lastUnitOnlyRooms, true) && $free > 1) {
            $this->log->item($f, true, PlanIssue::LAST_MINUTE_LAST_UNIT_ONLY, sprintf('In this room the last-minute discount is for the last free unit only (%d free).', $free), $params);
        }
    }
}
