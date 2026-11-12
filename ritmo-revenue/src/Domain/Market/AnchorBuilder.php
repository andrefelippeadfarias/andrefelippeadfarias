<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market;

use DateTimeInterface;
use InvalidArgumentException;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Builds and maintains the market anchors: the percentiles of the public nightly price of the
 * comparable competitors, one anchor per night.
 *
 * - An anchor built from the samples of its own night is "measured".
 * - A night without samples falls back to the median of the measured anchors of the same night
 *   type in the same month (see AnchorLookup); that anchor is an estimate ("fallback").
 * - An anchor is weak when its sample is small or old; the pace engine then takes half steps.
 *
 * Reference: ancora_mercado.construir, ancora_mercado.substitui and the "fraco" test of pace._plano.
 */
final class AnchorBuilder
{
    /** Day of the month that stands for a whole month when measuring how far a night is from it. */
    public const MONTH_ANCHOR_DAY = 15;

    private const PERCENTILES = ['p25', 'p50', 'p75', 'p90', 'max'];

    /**
     * @param int $decimals decimals kept in the percentiles; the reference keeps whole amounts
     */
    public function __construct(
        private readonly RuleSet $rules,
        private readonly int $decimals = 0,
    ) {
        if ($decimals < 0 || $decimals > 4) {
            throw new InvalidArgumentException('Anchor percentiles keep between 0 and 4 decimals.');
        }
    }

    /**
     * The measured anchor of one night, or null when no sample is for that night.
     *
     * Samples of other nights are ignored. When samples name their source, only the newest
     * sample of each source counts. The anchor is dated by its newest sample.
     *
     * @param list<PriceSample> $samples
     */
    public function fromSamples(DateTimeInterface $night, array $samples): ?MarketAnchor
    {
        $used = $this->newestPerSource(Days::key($night), $samples);
        if ($used === []) {
            return null;
        }

        $prices = array_map(static fn (PriceSample $sample): float => $sample->publicPrice, $used);
        $captured = array_map(static fn (PriceSample $sample): string => Days::key($sample->capturedAt), $used);

        return new MarketAnchor(
            Days::normalize($night),
            Num::roundHalfEven(Quantile::of($prices, 0.25), $this->decimals),
            Num::roundHalfEven(Quantile::of($prices, 0.5), $this->decimals),
            Num::roundHalfEven(Quantile::of($prices, 0.75), $this->decimals),
            Num::roundHalfEven(Quantile::of($prices, 0.9), $this->decimals),
            Num::roundHalfEven(max($prices), $this->decimals),
            count($prices),
            Days::date(max($captured)),
            AnchorOrigin::Measured,
        );
    }

    /**
     * One measured anchor per night that has samples.
     *
     * @param list<PriceSample> $samples of any nights
     *
     * @return array<string, MarketAnchor> 'YYYY-MM-DD' => anchor, ascending
     */
    public function build(array $samples): array
    {
        $byNight = [];
        foreach ($samples as $sample) {
            $byNight[Days::key($sample->night)][] = $sample;
        }
        ksort($byNight);

        $anchors = [];
        foreach ($byNight as $key => $ofNight) {
            $anchor = $this->fromSamples(Days::date($key), $ofNight);
            if ($anchor !== null) {
                $anchors[$key] = $anchor;
            }
        }

        return $anchors;
    }

    /**
     * The anchors in force after new ones arrive: a new anchor takes the place of the one already
     * held for its night only when replaces() says so.
     *
     * @param array<string, MarketAnchor> $existing 'YYYY-MM-DD' => anchor
     * @param array<string, MarketAnchor> $fresh    'YYYY-MM-DD' => anchor
     *
     * @return array<string, MarketAnchor> ascending
     */
    public function merge(array $existing, array $fresh, DateTimeInterface $today): array
    {
        $merged = $existing;
        foreach ($fresh as $key => $anchor) {
            if (!isset($merged[$key]) || $this->replaces($anchor, $merged[$key], $today)) {
                $merged[$key] = $anchor;
            }
        }
        ksort($merged);

        return $merged;
    }

    /**
     * Does a new anchor take the place of the one already held for the same night?
     *
     * A measured anchor is worth more than an estimate: an estimate does not replace a measured
     * anchor that is still fresh (at most "pace.max_age_days" old), and a fresh measured anchor
     * always replaces an estimate. Otherwise the newer capture wins and, on the same date, the
     * larger sample (an equal one replaces).
     */
    public function replaces(MarketAnchor $new, MarketAnchor $old, DateTimeInterface $today): bool
    {
        $newMeasured = $new->origin === AnchorOrigin::Measured;
        $oldMeasured = $old->origin === AnchorOrigin::Measured;
        if ($oldMeasured && !$newMeasured && $this->isFresh($old, $today)) {
            return false;
        }
        if ($newMeasured && !$oldMeasured && $this->isFresh($new, $today)) {
            return true;
        }

        $newCaptured = $new->capturedAt === null ? '' : Days::key($new->capturedAt);
        $oldCaptured = $old->capturedAt === null ? '' : Days::key($old->capturedAt);
        if ($newCaptured !== $oldCaptured) {
            return strcmp($newCaptured, $oldCaptured) > 0;
        }

        return $new->n >= $old->n;
    }

    /**
     * Days since the anchor's sample was taken; 0 when the capture date is unknown.
     */
    public function ageInDays(MarketAnchor $anchor, DateTimeInterface $today): int
    {
        return $anchor->capturedAt === null ? 0 : Days::diff($anchor->capturedAt, $today);
    }

    /**
     * True when the sample is smaller than "pace.min_n" or older than "pace.max_age_days".
     * A fallback anchor has no sample of its own (n = 0), so it is weak whenever a minimum
     * sample is required.
     */
    public function isWeak(MarketAnchor $anchor, DateTimeInterface $today): bool
    {
        return $anchor->n < $this->rules->minSampleSize()
            || $this->ageInDays($anchor, $today) > $this->rules->maxSampleAgeDays();
    }

    /**
     * The fallback anchors: for each night type and month, the median of every percentile of the
     * measured anchors of that type in that month.
     *
     * Each fallback is dated the 15th of its month, has n = 0 and carries the newest capture
     * date of the anchors behind it.
     *
     * @param array<string, MarketAnchor> $anchors 'YYYY-MM-DD' => anchor
     *
     * @return array<string, array<string, MarketAnchor>> night type => 'YYYY-MM' => fallback, months ascending
     */
    public function monthlyFallbacks(array $anchors): array
    {
        ksort($anchors);
        $groups = [];
        foreach ($anchors as $anchor) {
            if ($anchor->origin !== AnchorOrigin::Measured) {
                continue;
            }
            $type = $this->rules->nightType($anchor->date)->value;
            $groups[$type][substr(Days::key($anchor->date), 0, 7)][] = $anchor;
        }

        $fallbacks = [];
        foreach ($groups as $type => $months) {
            foreach ($months as $month => $ofMonth) {
                $fallbacks[$type][$month] = $this->medianAnchor($month, $ofMonth);
            }
        }

        return $fallbacks;
    }

    /**
     * @param non-empty-list<MarketAnchor> $anchors
     */
    private function medianAnchor(string $month, array $anchors): MarketAnchor
    {
        $values = [];
        foreach (self::PERCENTILES as $percentile) {
            $values[$percentile] = Num::roundHalfEven(
                Quantile::median(array_map(static fn (MarketAnchor $anchor): float => $anchor->$percentile, $anchors)),
                $this->decimals
            );
        }
        $captured = array_filter(array_map(
            static fn (MarketAnchor $anchor): ?string => $anchor->capturedAt === null ? null : Days::key($anchor->capturedAt),
            $anchors
        ));

        return new MarketAnchor(
            Days::date(sprintf('%s-%02d', $month, self::MONTH_ANCHOR_DAY)),
            $values['p25'],
            $values['p50'],
            $values['p75'],
            $values['p90'],
            $values['max'],
            0,
            $captured === [] ? null : Days::date(max($captured)),
            AnchorOrigin::Fallback,
        );
    }

    private function isFresh(MarketAnchor $anchor, DateTimeInterface $today): bool
    {
        return $anchor->capturedAt !== null
            && Days::diff($anchor->capturedAt, $today) <= $this->rules->maxSampleAgeDays();
    }

    /**
     * The samples of one night; when sources are named, only the newest sample of each.
     *
     * @param list<PriceSample> $samples
     *
     * @return list<PriceSample>
     */
    private function newestPerSource(string $nightKey, array $samples): array
    {
        $anonymous = [];
        $bySource = [];
        foreach ($samples as $sample) {
            if (Days::key($sample->night) !== $nightKey) {
                continue;
            }
            if ($sample->source === '') {
                $anonymous[] = $sample;
            } elseif (!isset($bySource[$sample->source]) || $sample->capturedAt >= $bySource[$sample->source]->capturedAt) {
                $bySource[$sample->source] = $sample;
            }
        }

        return array_merge($anonymous, array_values($bySource));
    }
}
