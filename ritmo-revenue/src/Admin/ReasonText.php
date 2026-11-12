<?php

declare(strict_types=1);

namespace Ritmo\Admin;

use Ritmo\Domain\Pricing\RuleCode;

/**
 * Turns the rule trace of a decision into sentences a person can read: why a night would get
 * the floor and ceiling the shadow run proposes. Amounts are in the sent price plan.
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
    public static function sentences(array $trace): array
    {
        $sentences = [];
        foreach ($trace as $entry) {
            $sentence = self::sentence((string) ($entry['code'] ?? ''), is_array($entry['params'] ?? null) ? $entry['params'] : []);
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
            'LADDER_PRICE' => __('Sales ladder', 'ritmo-revenue'),
            'SOLD_OUT_PROTECTION' => __('Protection of a sold-out night', 'ritmo-revenue'),
            'RELATION_AT_LEAST' => __('Relation: at least another room', 'ritmo-revenue'),
            'RELATION_BETWEEN' => __('Relation: between two rooms', 'ritmo-revenue'),
            'RELATION_PREMIUM_OVER_MAX' => __('Relation: above the dearest room', 'ritmo-revenue'),
            'RELATION_DISCOUNT_BAND' => __('Relation: discount band', 'ritmo-revenue'),
            'RELATION_OCCUPANCY_SWITCH' => __('Relation: occupancy switch', 'ritmo-revenue'),
            'CEILING_RELEASE_DELETE' => __('Ceiling freed (rule deleted first)', 'ritmo-revenue'),
            default => $code,
        };
    }

    /**
     * @param list<array{code: string, params: array<string, mixed>}> $trace
     */
    public static function describe(array $trace): string
    {
        return implode(' ', self::sentences($trace));
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function sentence(string $code, array $p): ?string
    {
        return match ($code) {
            RuleCode::NIGHT => self::night($p),
            RuleCode::PRICE_WITHIN_RULE_IN_FORCE => sprintf(
                /* translators: 1: price read, 2: price the date rule in force allows */
                __('The price read, %1$s, is outside the date rule in force: the engine starts from %2$s.', 'ritmo-revenue'),
                self::number($p['read'] ?? null, 2),
                self::number($p['price'] ?? null, 2)
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
                /* translators: 1: market percentile, 2: public price at that percentile, 3: the same price in the sent plan */
                __('Target: market percentile %1$s, %2$s public, %3$s sent.', 'ritmo-revenue'),
                self::number($p['percentile'] ?? null),
                self::number($p['public'] ?? null, 2),
                self::number($p['sent'] ?? null, 2)
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
                self::number($p['from'] ?? null, 2),
                self::number($p['to'] ?? null, 2),
                self::number($p['max_percent'] ?? null, 1)
            ),
            RuleCode::HELD_AT_FLOOR => sprintf(
                /* translators: 1: floor amount, 2: which floor */
                __('Held at the hard floor of %1$s (%2$s).', 'ritmo-revenue'),
                self::number($p['floor'] ?? null, 2),
                self::floorLabel((string) ($p['label'] ?? ''))
            ),
            RuleCode::CAPPED_AT_LISTING_MAX => sprintf(
                /* translators: %s: highest floor allowed by the listing maximum */
                __('Capped at %s by the listing maximum.', 'ritmo-revenue'),
                self::number($p['highest_floor'] ?? null, 2)
            ),
            RuleCode::TODAY_NEVER_RISES => __('Tonight never goes up.', 'ritmo-revenue'),
            RuleCode::WITHIN_TOLERANCE => __('The change is within the tolerance: nothing to do.', 'ritmo-revenue'),
            RuleCode::CADENCE_PREVIOUS_STEP_NOT_PUSHED => __('Waiting: the previous step has not reached the sales channels.', 'ritmo-revenue'),
            RuleCode::CADENCE_STEP_WITHIN_24H => __('Waiting: there was a step in this direction in the last 24 hours.', 'ritmo-revenue'),
            RuleCode::CEILING_AT_TARGET => sprintf(
                /* translators: %s: ceiling amount */
                __('Ceiling at the target, %s, so the pricing system cannot jump past the step.', 'ritmo-revenue'),
                self::number($p['ceiling'] ?? null, 2)
            ),
            RuleCode::CEILING_FOLLOWS_FLOOR => sprintf(
                /* translators: %s: ceiling amount */
                __('Ceiling follows the floor down to %s.', 'ritmo-revenue'),
                self::number($p['ceiling'] ?? null, 2)
            ),
            RuleCode::PACE_STEP => sprintf(
                /* translators: 1: "up" or "down", 2: new floor, 3: new ceiling */
                __('Proposed step %1$s: floor %2$s, ceiling %3$s.', 'ritmo-revenue'),
                ($p['direction'] ?? '') === 'down' ? __('down', 'ritmo-revenue') : __('up', 'ritmo-revenue'),
                self::number($p['min_price'] ?? null, 2),
                self::number($p['max_price'] ?? null, 2)
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
        /* translators: 1: kind of night, 2: days until the night */
        $text = sprintf(__('%1$s, %2$s days out.', 'ritmo-revenue'), $type, self::number($p['days_out'] ?? null));
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
            'listing_min' => __('listing minimum', 'ritmo-revenue'),
            'reference_min_fraction' => __('safety limit', 'ritmo-revenue'),
            'quality_floor' => __('quality floor', 'ritmo-revenue'),
            'holiday_floor' => __('holiday floor', 'ritmo-revenue'),
            'owner_floor' => __('owner floor', 'ritmo-revenue'),
            default => $label,
        };
    }

    private static function number(mixed $value, int $decimals = 0): string
    {
        if (!is_int($value) && !is_float($value)) {
            return '?';
        }
        $text = number_format((float) $value, $decimals, '.', '');

        return $decimals > 0 && str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
