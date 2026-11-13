<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Support\Num;

/**
 * Tonight's price never goes up by a write, checked when the token is issued with tonight as
 * read in this run:
 * - a floor for tonight above the current price (unless it is not above the floor in force);
 * - a ceiling for tonight above the ceiling in force (raising or releasing it);
 * - deleting tonight's rule when it holds a ceiling or a price of its own, unless the rewrite of
 *   the same group caps tonight at or below the current price.
 * Tonight without a known state, when a step touches it, refuses too.
 * The M2 validator already refuses a floor that raises tonight; ceilings and deletions are new
 * here (WRITE.md, divergence C10: the reference does not check them).
 */
final class TonightGuard
{
    /**
     * @param list<WriteStep>             $steps
     * @param array<string, TonightState> $tonight room code => tonight as read
     * @param array<string, float>        $bases   room code => listing base
     *
     * @throws WriteRefused
     */
    public function check(array $steps, string $today, array $tonight, array $bases): void
    {
        foreach ($steps as $step) {
            if ($step->operation === WriteOperation::OverridesWrite) {
                foreach ($step->payload as $entry) {
                    if (($entry['date'] ?? '') === $today) {
                        $this->checkEntry($step, $entry, self::state($tonight, $step->roomCode), self::base($bases, $step->roomCode));
                    }
                }
            } elseif ($step->operation === WriteOperation::OverridesDelete && in_array($today, $step->dates(), true)) {
                $this->checkDeletion($step, $steps, $today, self::state($tonight, $step->roomCode), self::base($bases, $step->roomCode));
            }
        }
    }

    /**
     * @param array<string, string> $entry
     */
    private function checkEntry(WriteStep $step, array $entry, TonightState $state, float $base): void
    {
        if (isset($entry['min_price'])) {
            $percent = (int) $entry['min_price'];
            $stored = $step->isGroupRewrite() ? null : $state->rule?->minPercent;
            if ($stored === null || $percent > $stored) {
                if (Num::percentToAmount($percent, $base, 2) > self::price($state)) {
                    throw new WriteRefused(WriteRefused::RAISE_TODAY_FLOOR, 'A floor would raise tonight\'s price.', $step->roomCode);
                }
            }
        }
        if (isset($entry['max_price']) && !$step->isGroupRewrite() && $state->rule !== null) {
            $percent = (int) $entry['max_price'];
            $raises = ($state->rule->maxPercent !== null && $percent > $state->rule->maxPercent)
                || ($state->rule->maxFixed !== null && Num::percentToAmount($percent, $base, 2) > $state->rule->maxFixed);
            if ($raises) {
                throw new WriteRefused(WriteRefused::RAISE_TODAY_CEILING, 'A ceiling would let tonight\'s price rise.', $step->roomCode);
            }
        }
    }

    /**
     * @param list<WriteStep> $steps
     */
    private function checkDeletion(WriteStep $deletion, array $steps, string $today, TonightState $state, float $base): void
    {
        if (!$state->ruleHoldsThePriceDown()) {
            return;
        }
        foreach ($steps as $step) {
            if ($deletion->groupKey === '' || $step->groupKey !== $deletion->groupKey || !$step->isGroupRewrite()) {
                continue;
            }
            foreach ($step->payload as $entry) {
                if (($entry['date'] ?? '') === $today && isset($entry['max_price']) && Num::percentToAmount((int) $entry['max_price'], $base, 2) <= self::price($state)) {
                    return;
                }
            }
        }

        throw new WriteRefused(WriteRefused::RAISE_TODAY_DELETION, 'Deleting tonight\'s rule would let tonight\'s price rise.', $deletion->roomCode);
    }

    /**
     * @param array<string, TonightState> $tonight
     */
    private static function state(array $tonight, string $roomCode): TonightState
    {
        return $tonight[$roomCode] ?? throw new WriteRefused(WriteRefused::TONIGHT_UNKNOWN, 'Tonight was not read for this room in this run.', $roomCode);
    }

    private static function price(TonightState $state): float
    {
        return $state->price ?? throw new WriteRefused(WriteRefused::TONIGHT_UNKNOWN, 'Tonight\'s price was not read in this run.', $state->roomCode);
    }

    /**
     * @param array<string, float> $bases
     */
    private static function base(array $bases, string $roomCode): float
    {
        return $bases[$roomCode] ?? throw new WriteRefused(WriteRefused::TONIGHT_UNKNOWN, 'The listing base of the room is unknown.', $roomCode);
    }
}
