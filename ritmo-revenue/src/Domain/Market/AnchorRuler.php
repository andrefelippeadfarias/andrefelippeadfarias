<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Pricing\Ladder\RulerNight;

/**
 * The market ruler of a run built from the property's market anchors: one series, named
 * "market", with the anchor's percentiles (25, 50, 75, 90 and 100 for the highest price seen) as
 * public prices, and the night type of the rule set. A sales ladder strategy reads it by series
 * name and percentile; nights without an anchor have no ruler night, so no strategy acts there.
 *
 * The public factors and the reference prices a ruler night may carry are not known from the
 * anchors and stay empty: a rule that needs them falls back as the ladder documents.
 */
final class AnchorRuler
{
    public const SERIES = 'market';

    private function __construct()
    {
    }

    /**
     * @param array<string, MarketAnchor> $anchors by night ("YYYY-MM-DD")
     */
    public static function of(array $anchors, RuleSet $rules): MarketRuler
    {
        $nights = [];
        foreach ($anchors as $anchor) {
            if ($anchor->n === 0) {
                continue;
            }
            $nights[] = new RulerNight(
                $anchor->date,
                $rules->nightType($anchor->date)->value,
                [self::SERIES => ['25' => $anchor->p25, '50' => $anchor->p50, '75' => $anchor->p75, '90' => $anchor->p90, '100' => $anchor->max]],
            );
        }

        return new MarketRuler($nights);
    }
}
