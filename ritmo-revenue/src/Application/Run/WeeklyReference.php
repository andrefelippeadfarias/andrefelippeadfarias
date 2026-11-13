<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use DateTimeImmutable;
use Ritmo\Contracts\KeyValueStore;

/**
 * The listing base and minimum of each room at the start of the property's week: the first values
 * a run of the week reads are kept (option "weekly_reference_<property>") and every later run of
 * the same week compares with them, so the weekly limit on listing changes adds up over the week
 * (validator, ListingCheck). A new week starts again from what is read then.
 */
final class WeeklyReference
{
    private const KEY_PREFIX = 'weekly_reference_';

    public function __construct(private readonly KeyValueStore $store)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $listings room code => listing as the run stored it (min, base)
     * @param DateTimeImmutable                   $today    the property's day
     *
     * @return array<string, array{base?: float, min?: float}> room code => values at the start of the week
     */
    public function of(string $propertyId, array $listings, DateTimeImmutable $today): array
    {
        $week = $today->format('o-\WW');
        $key = self::KEY_PREFIX . $propertyId;
        $stored = $this->store->get($key);
        $rooms = is_array($stored) && ($stored['week'] ?? null) === $week && is_array($stored['rooms'] ?? null) ? $stored['rooms'] : [];
        $changed = false;
        foreach ($listings as $code => $listing) {
            if (isset($rooms[$code])) {
                continue;
            }
            $values = [];
            foreach (['base', 'min'] as $field) {
                if (is_int($listing[$field] ?? null) || is_float($listing[$field] ?? null)) {
                    $values[$field] = (float) $listing[$field];
                }
            }
            if ($values !== []) {
                $rooms[(string) $code] = $values;
                $changed = true;
            }
        }
        if ($changed) {
            $this->store->set($key, ['week' => $week, 'rooms' => $rooms]);
        }

        return $rooms;
    }
}
