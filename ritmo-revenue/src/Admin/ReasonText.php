<?php

declare(strict_types=1);

namespace Ritmo\Admin;

use Ritmo\Admin\Components\Money;
use Ritmo\Domain\Pricing\Ladder\LadderHardFloor;
use Ritmo\Domain\Pricing\OrderSalesRamp;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Pricing\Terms\NightTermsResolver;

/**
 * Turns the rule trace of a decision into sentences a person can read: why a night would get
 * the floor and ceiling the shadow run proposes. Amounts are in the sent price plan, in the
 * currency of the property (numbers in the person's format when no currency is given).
 */
final class ReasonText
{
    private function __construct()
    {
    }

    /**
     * @param list<array{code: string, params: array<string, mixed>}> $trace
     *
     * @return list<string> one sentence per rule that applied, in the order the engine applied them
     */
    public static function sentences(array $trace, string $currency = ''): array
    {
        $sentences = [];
        foreach ($trace as $entry) {
            $sentence = self::sentence((string) ($entry['code'] ?? ''), is_array($entry['params'] ?? null) ? $entry['params'] : [], $currency);
            if ($sentence !== null) {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    /**
     * The name of what priced a room night (the reason code of a plan item).
     */
    public static function reason(string $code): string
    {
        return match ($code) {
            'PACE_STEP' => __('Pace step', 'ritmo-revenue'),
            'ORDER_SALES_RAMP' => __('Sales ramp of an owner order', 'ritmo-revenue'),
            'LADDER_PRICE' => __('Sales ladder', 'ritmo-revenue'),
            'SOLD_OUT_PROTECTION' => __('Protection of a sold-out night', 'ritmo-revenue'),
            'RELATION_AT_LEAST' => __('Relation: at least another room', 'ritmo-revenue'),
            'RELATION_BETWEEN' => __('Relation: between two rooms', 'ritmo-revenue'),
            'RELATION_PREMIUM_OVER_MAX' => __('Relation: above the dearest room', 'ritmo-revenue'),
            'RELATION_DISCOUNT_BAND' => __('Relation: discount band', 'ritmo-revenue'),
            'RELATION_OCCUPANCY_SWITCH' => __('Relation: occupancy switch', 'ritmo-revenue'),
            'CEILING_RELEASE_DELETE' => __('Ceiling freed (rule deleted first)', 'ritmo-revenue'),
            'undo' => __('Undo of an earlier change', 'ritmo-revenue'),
            default => $code,
        };
    }

    /**
     * @param list<array{code: string, params: array<string, mixed>}> $trace
     */
    public static function describe(array $trace, string $currency = ''): string
    {
        return implode(' ', self::sentences($trace, $currency));
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function sentence(string $code, array $p, string $currency): ?string
    {
        $money = static fn (mixed $value): string => self::money($value, $currency);

        return match ($code) {
            RuleCode::NIGHT => self::night($p),
            RuleCode::PRICE_WITHIN_RULE_IN_FORCE => sprintf(
                /* translators: 1: price read, 2: price the date rule in force allows */
                __('The price read, %1$s, is outside the date rule in force: the engine starts from %2$s.', 'ritmo-revenue'),
                $money($p['read'] ?? null),
                $money($p['price'] ?? null)
            ),
            RuleCode::PACE_STATUS => sprintf(
                /* translators: 1: pace status, 2: units sold, 3: units on sale, 4: percent sold, 5: percent the curve expects */
                __('Pace %1$s: %2$s of %3$s units sold (%4$s%% against %5$s%% expected).', 'ritmo-revenue'),
                self::status((string) ($p['status'] ?? '')),
                self::number($p['sold'] ?? null),
                self::number($p['units'] ?? null),
                self::number($p['sold_percent'] ?? null, 1),
                self::number($p['expected_percent'] ?? null, 1)
            ),
            RuleCode::MARKET_TARGET => sprintf(
                /* translators: 1: market percentile, 2: price guests see at that percentile, 3: the same price in the plan sent to the pricing system */
                __('Target: the market price at percentile %1$s, %2$s as guests see it (%3$s in the plan sent to the pricing system).', 'ritmo-revenue'),
                self::number($p['percentile'] ?? null),
                $money($p['public'] ?? null),
                $money($p['sent'] ?? null)
            ),
            RuleCode::WEAK_ANCHOR => sprintf(
                /* translators: 1: sample size, 2: age in days */
                __('Weak market data (%1$s prices, %2$s days old): half a step at most.', 'ritmo-revenue'),
                self::number($p['n'] ?? null),
                self::number($p['age_days'] ?? null)
            ),
            RuleCode::AHEAD_NEVER_LOWERS => __('Ahead of pace: the price does not go down.', 'ritmo-revenue'),
            RuleCode::STEP => sprintf(
                /* translators: 1: price before, 2: price after, 3: largest step in percent */
                __('One step from %1$s to %2$s (at most %3$s%%).', 'ritmo-revenue'),
                $money($p['from'] ?? null),
                $money($p['to'] ?? null),
                self::number($p['max_percent'] ?? null, 1)
            ),
            RuleCode::ORDER_SALES_RAMP => sprintf(
                /* translators: 1: order id, 2: line of the ramp, 3: price reached, 4: floor of the ramp */
                __('Sales ramp of order %1$s: line %2$s, price %3$s over a floor of %4$s.', 'ritmo-revenue'),
                (string) ($p['order'] ?? ''),
                self::number($p['line'] ?? null, 2),
                $money($p['to'] ?? null),
                $money($p['floor'] ?? null)
            ),
            RuleCode::HELD_AT_FLOOR => sprintf(
                /* translators: 1: floor amount, 2: which floor */
                __('Held at the hard floor of %1$s (%2$s).', 'ritmo-revenue'),
                $money($p['floor'] ?? null),
                self::floorLabel((string) ($p['label'] ?? ''))
            ),
            RuleCode::CAPPED_AT_LISTING_MAX => sprintf(
                /* translators: %s: highest floor allowed by the listing maximum */
                __('Capped at %s by the listing maximum.', 'ritmo-revenue'),
                $money($p['highest_floor'] ?? null)
            ),
            RuleCode::TODAY_NEVER_RISES => __('Tonight never goes up.', 'ritmo-revenue'),
            RuleCode::WITHIN_TOLERANCE => __('The change is within the tolerance: nothing to do.', 'ritmo-revenue'),
            RuleCode::CADENCE_PREVIOUS_STEP_NOT_PUSHED => __('Waiting: the previous step has not reached the sales channels.', 'ritmo-revenue'),
            RuleCode::CADENCE_STEP_WITHIN_24H => __('Waiting: there was a step in this direction in the last 24 hours.', 'ritmo-revenue'),
            RuleCode::CEILING_AT_TARGET => sprintf(
                /* translators: %s: ceiling amount */
                __('Ceiling at the target, %s, so the pricing system cannot jump past the step.', 'ritmo-revenue'),
                $money($p['ceiling'] ?? null)
            ),
            RuleCode::CEILING_FOLLOWS_FLOOR => sprintf(
                /* translators: %s: ceiling amount */
                __('Ceiling follows the floor down to %s.', 'ritmo-revenue'),
                $money($p['ceiling'] ?? null)
            ),
            RuleCode::PACE_STEP => sprintf(
                /* translators: 1: "up" or "down", 2: new floor, 3: new ceiling */
                __('Proposed step %1$s: floor %2$s, ceiling %3$s.', 'ritmo-revenue'),
                ($p['direction'] ?? '') === 'down' ? __('down', 'ritmo-revenue') : __('up', 'ritmo-revenue'),
                $money($p['min_price'] ?? null),
                $money($p['max_price'] ?? null)
            ),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function night(array $p): string
    {
        $type = match ($p['night_type'] ?? '') {
            'weekend' => __('Weekend night', 'ritmo-revenue'),
            'holiday' => __('Holiday night', 'ritmo-revenue'),
            default => __('Weekday night', 'ritmo-revenue'),
        };
        $days = $p['days_out'] ?? null;
        $text = match (true) {
            /* translators: %s: kind of night */
            !is_int($days) && !is_float($days) => sprintf(__('%s, days out not known.', 'ritmo-revenue'), $type),
            /* translators: %s: kind of night */
            (int) $days === 0 => sprintf(__('%s, tonight.', 'ritmo-revenue'), $type),
            /* translators: 1: kind of night, 2: days until the night */
            default => sprintf(_n('%1$s, %2$s day out.', '%1$s, %2$s days out.', (int) $days, 'ritmo-revenue'), $type, self::number($days)),
        };
        if (is_string($p['period'] ?? null) && $p['period'] !== '') {
            /* translators: %s: name of the configured period */
            $text .= ' ' . sprintf(__('Period: %s.', 'ritmo-revenue'), $p['period']);
        }

        return $text;
    }

    private static function status(string $status): string
    {
        return match ($status) {
            'ahead' => __('ahead', 'ritmo-revenue'),
            'on_pace' => __('on pace', 'ritmo-revenue'),
            'behind' => __('behind', 'ritmo-revenue'),
            'very_behind' => __('very behind', 'ritmo-revenue'),
            default => $status,
        };
    }

    private static function floorLabel(string $label): string
    {
        return match ($label) {
            Floors::LISTING_MIN => __('listing minimum', 'ritmo-revenue'),
            Floors::REFERENCE_MIN_FRACTION => __('safety limit', 'ritmo-revenue'),
            Floors::QUALITY => __('quality floor', 'ritmo-revenue'),
            Floors::HOLIDAY => __('holiday floor', 'ritmo-revenue'),
            Floors::OWNER => __('owner floor', 'ritmo-revenue'),
            OrderSalesRamp::FALLBACK_FLOOR => __('fallback floor of the order', 'ritmo-revenue'),
            OrderSalesRamp::DESCENT_STEP => __('descent step of the order', 'ritmo-revenue'),
            OrderSalesRamp::RETURN_LIMIT => __('climb back limited to one step', 'ritmo-revenue'),
            NightTermsResolver::ORDER_FLOOR => __('minimum price of an owner order', 'ritmo-revenue'),
            NightTermsResolver::MARKET_FLOOR => __('market floor', 'ritmo-revenue'),
            NightTermsResolver::PREMIUM_FLOOR => __('floor of a premium relation', 'ritmo-revenue'),
            LadderHardFloor::HOLIDAY_SAFETY_FLOOR => __('holiday safety floor', 'ritmo-revenue'),
            default => __('another floor', 'ritmo-revenue'),
        };
    }

    /**
     * The floor labels the engine writes into a trace (Floors, OrderSalesRamp, NightTermsResolver,
     * LadderHardFloor), each with its own words.
     *
     * @return list<string>
     */
    public static function floorLabels(): array
    {
        return [
            Floors::LISTING_MIN, Floors::REFERENCE_MIN_FRACTION, Floors::QUALITY, Floors::HOLIDAY, Floors::OWNER,
            OrderSalesRamp::FALLBACK_FLOOR, OrderSalesRamp::DESCENT_STEP, OrderSalesRamp::RETURN_LIMIT,
            NightTermsResolver::ORDER_FLOOR, NightTermsResolver::MARKET_FLOOR, NightTermsResolver::PREMIUM_FLOOR,
            LadderHardFloor::HOLIDAY_SAFETY_FLOOR,
        ];
    }

    /**
     * An amount in the currency of the property; without one, a number in the person's format.
     */
    private static function money(mixed $value, string $currency): string
    {
        if (!is_int($value) && !is_float($value)) {
            return '?';
        }

        return $currency === '' ? self::number($value, 2) : Money::format((float) $value, $currency);
    }

    /**
     * A number in the person's format, with up to $decimals decimals (none when it is whole).
     */
    private static function number(mixed $value, int $decimals = 0): string
    {
        if (!is_int($value) && !is_float($value)) {
            return '?';
        }
        $rounded = round((float) $value, $decimals);
        $shown = 0;
        while ($shown < $decimals && abs(round($rounded, $shown) - $rounded) > 0.0000001) {
            ++$shown;
        }

        return number_format_i18n($rounded, $shown);
    }
}
