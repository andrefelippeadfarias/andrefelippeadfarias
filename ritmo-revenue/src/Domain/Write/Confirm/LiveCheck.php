<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

use InvalidArgumentException;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Num;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * The "live" level of a written date rule, on the recalculated calendar of its room:
 *
 * - the rule is in force and applies the written floor (RuleLiveness);
 * - the price is not under the floor nor over the ceiling beyond PercentSlack (not judged on a
 *   sold-out night: the price proves nothing there);
 * - the minimum stay is the written one, or the night is isolated (warning);
 * - a deleted rule is no longer applied.
 *
 * Floors and ceilings are compared as the pricing system applies them, base x (1 + pct / 100) in
 * whole currency, not the raw amount the engine asked (decision D9, divergence C9).
 *
 * Reference: ler_recalculo.py (main, regra_inativa, tolerancia_percentual, dia_isolado).
 */
final class LiveCheck
{
    /**
     * @param float $base     the listing base the percentages are over
     * @param bool  $isolated the night is free between two sold-out nights (IsolatedNight)
     *
     * @throws InvalidArgumentException for a night of another system
     */
    public function check(WrittenNight $written, float $base, ?PriceNight $recalculated, bool $isolated = false): LiveVerdict
    {
        if ($written->provider !== Provider::Pricing) {
            throw new InvalidArgumentException('The live check is for date rules of the pricing system.');
        }
        if ($recalculated === null) {
            return new LiveVerdict(LiveStatus::NoData, false);
        }
        if ($written->kind === WriteKind::Delete) {
            return new LiveVerdict($recalculated->hasDateOverride ? LiveStatus::DeletionStillLive : LiveStatus::Live, false);
        }
        $rule = $written->intended();
        $floor = self::amount($rule->get(ChangeField::FLOOR_PCT), $base);
        $ceiling = self::amount($rule->get(ChangeField::CEILING_PCT), $base);
        $issue = RuleLiveness::check($floor !== null || $ceiling !== null, $floor, $recalculated->hasDateOverride, $recalculated->minPrice);
        if ($issue !== null) {
            return new LiveVerdict($issue === LivenessIssue::ExpiredOrOutOfReach ? LiveStatus::Inactive : LiveStatus::FloorDiffers, false);
        }
        $priceChecked = !$recalculated->isSoldOut();
        if ($priceChecked && $floor !== null && $recalculated->price < $floor - PercentSlack::of($floor, $base)) {
            return new LiveVerdict(LiveStatus::BelowFloor);
        }
        if ($priceChecked && $ceiling !== null && $recalculated->price > $ceiling + PercentSlack::of($ceiling, $base)) {
            return new LiveVerdict(LiveStatus::AboveCeiling);
        }
        $stay = $rule->get(ChangeField::MIN_STAY);
        if ($stay !== null && $recalculated->minStay !== (int) $stay) {
            return $isolated
                ? new LiveVerdict(LiveStatus::Live, $priceChecked, [LiveVerdict::ISOLATED_NIGHT_STAY])
                : new LiveVerdict(LiveStatus::StayDiffers, $priceChecked);
        }

        return new LiveVerdict(LiveStatus::Live, $priceChecked);
    }

    private static function amount(int|float|string|null $percent, float $base): ?float
    {
        return is_int($percent) || is_float($percent) ? Num::percentToAmount((float) $percent, $base) : null;
    }
}
