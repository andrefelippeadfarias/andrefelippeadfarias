<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\ListingInfo;

/**
 * The listings the pricing system showed at the wizard's step 3 (kept as plain values: id, name,
 * minimum, base, maximum, currency), so step 4 suggests the currency and step 5 starts with one
 * room row per listing instead of an empty table. Every suggested value stays editable: the PMS
 * room id is only a guess from the end of the listing id (until the PMS can list its rooms,
 * decision D4), and the units are not known.
 */
final class ListingSuggestions
{
    public const KEY = 'wizard_listings';

    /** Listings kept: a small inn has a handful; more than this is typed by hand. */
    public const MAX = 50;

    public function __construct(private readonly KeyValueStore $settings)
    {
    }

    /**
     * @param list<ListingInfo> $listings
     */
    public function remember(array $listings): void
    {
        $kept = [];
        foreach (array_slice($listings, 0, self::MAX) as $listing) {
            $kept[] = ['ref' => $listing->ref, 'name' => $listing->name, 'min' => $listing->min, 'base' => $listing->base, 'max' => $listing->max, 'currency' => $listing->currency];
        }
        $this->settings->set(self::KEY, $kept);
    }

    /**
     * One room row per listing remembered, with a unique code made of the initials of its name.
     *
     * @param list<string> $taken codes already in use
     *
     * @return list<array{code: string, name: string, units: string, pms_ref: string, pricing_ref: string, base: string, min: string, max: string, tags: string}>
     */
    public function rows(array $taken = []): array
    {
        $rows = [];
        foreach ($this->stored() as $listing) {
            $code = self::code($listing['name'], $taken);
            $taken[] = $code;
            $rows[] = [
                'code' => $code,
                'name' => $listing['name'],
                'units' => '1',
                'pms_ref' => preg_match('/[^0-9](\d{1,12})$/', $listing['ref'], $m) === 1 ? $m[1] : '',
                'pricing_ref' => $listing['ref'],
                'base' => self::plain($listing['base']),
                'min' => self::plain($listing['min']),
                'max' => self::plain($listing['max']),
                'tags' => '',
            ];
        }

        return $rows;
    }

    /** The most frequent currency of the listings; null when none says. */
    public function currency(): ?string
    {
        $counts = array_count_values(array_filter(array_column($this->stored(), 'currency'), static fn (mixed $c): bool => is_string($c) && preg_match('/^[A-Z]{3}$/', $c) === 1));
        arsort($counts);

        return $counts === [] ? null : (string) array_key_first($counts);
    }

    /**
     * @return list<array{ref: string, name: string, min: float|null, base: float|null, max: float|null, currency: string|null}>
     */
    private function stored(): array
    {
        $out = [];
        foreach ((array) $this->settings->get(self::KEY, []) as $row) {
            if (!is_array($row) || !is_string($row['ref'] ?? null) || $row['ref'] === '') {
                continue;
            }
            $out[] = [
                'ref' => $row['ref'],
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'min' => is_numeric($row['min'] ?? null) ? (float) $row['min'] : null,
                'base' => is_numeric($row['base'] ?? null) ? (float) $row['base'] : null,
                'max' => is_numeric($row['max'] ?? null) ? (float) $row['max'] : null,
                'currency' => is_string($row['currency'] ?? null) ? strtoupper($row['currency']) : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $taken
     */
    private static function code(string $name, array $taken): string
    {
        preg_match_all('/[A-Za-z0-9]+/', $name, $words);
        $initials = strtoupper(implode('', array_map(static fn (string $w): string => $w[0], $words[0])));
        $code = Identifier::isCode($initials) ? substr($initials, 0, 8) : 'ROOM';
        $candidate = $code;
        for ($n = 2; in_array($candidate, $taken, true); ++$n) {
            $candidate = $code . $n;
        }

        return $candidate;
    }

    private static function plain(?float $amount): string
    {
        if ($amount === null) {
            return '';
        }

        return abs($amount - round($amount)) < 0.005 ? (string) (int) round($amount) : number_format($amount, 2, '.', '');
    }
}
