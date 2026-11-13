<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use Ritmo\Contracts\Exception\WriteBlocked;

/**
 * The body of a listing change: only the minimum, the base and the maximum, positive amounts in
 * order (min <= base <= max among those given). A listing change is written only under a token
 * issued with a human approval naming it (the write gate checks that, never in autonomous mode).
 * The route and body follow the provider's documented "update listings" call; they are not in the
 * project's verified API notes yet (WRITE.md, open decision D13), so the write settings keep
 * listing changes off by default.
 */
final class ListingUpdateBody
{
    public const FIELDS = ['min', 'base', 'max'];

    private const ORDER = ['min' => 0, 'base' => 1, 'max' => 2];

    /**
     * @param array<string, float> $fields field => amount
     *
     * @return array{listings: list<array<string, float|string>>}
     *
     * @throws WriteBlocked on another field, a non-positive amount or amounts out of order
     */
    public static function build(string $listingRef, string $pms, array $fields): array
    {
        if ($fields === []) {
            throw self::refused('a listing change names at least one of min, base and max');
        }
        $clean = [];
        foreach ($fields as $field => $amount) {
            if (!in_array($field, self::FIELDS, true)) {
                throw self::refused(sprintf('field "%s" of a listing is never written', $field));
            }
            if ((!is_float($amount) && !is_int($amount)) || !is_finite((float) $amount) || $amount <= 0) {
                throw self::refused('listing amounts are positive numbers');
            }
            $clean[$field] = (float) $amount;
        }
        uksort($clean, static fn (string $a, string $b): int => self::ORDER[$a] <=> self::ORDER[$b]);
        $previous = 0.0;
        foreach ($clean as $amount) {
            if ($amount < $previous) {
                throw self::refused('the minimum, base and maximum must be in order');
            }
            $previous = $amount;
        }

        return ['listings' => [['id' => $listingRef, 'pms' => $pms] + $clean]];
    }

    private static function refused(string $why): WriteBlocked
    {
        return WriteBlocked::forRequest('POST', 'pricing listing', $why);
    }
}
