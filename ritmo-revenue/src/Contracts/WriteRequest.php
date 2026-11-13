<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use InvalidArgumentException;

/**
 * What one write request changes, as a connector describes it to the write authority before
 * anything leaves the site: the operation, the targets (listing ids of the pricing system or room
 * ids of the PMS) with the nights of each, and the names of the fields sent.
 *
 * Nights are YYYY-MM-DD keys, sorted and unique; a recalculation or a listing change has none.
 */
final class WriteRequest
{
    /** @var array<string, list<string>> target => nights */
    public readonly array $nights;

    /** @var list<string> */
    public readonly array $fields;

    /**
     * @param array<string, list<string>> $nights target => nights (YYYY-MM-DD)
     * @param list<string>                $fields names of the fields sent
     *
     * @throws InvalidArgumentException on no target, an empty target or a malformed night
     */
    public function __construct(public readonly WriteOperation $operation, array $nights, array $fields = [])
    {
        if ($nights === []) {
            throw new InvalidArgumentException('A write request names at least one target.');
        }
        $normalised = [];
        foreach ($nights as $target => $dates) {
            if ((string) $target === '') {
                throw new InvalidArgumentException('A write target cannot be empty.');
            }
            foreach ($dates as $date) {
                if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    throw new InvalidArgumentException('A night of a write request is a YYYY-MM-DD key.');
                }
            }
            $unique = array_values(array_unique($dates));
            sort($unique);
            $normalised[(string) $target] = $unique;
        }
        ksort($normalised);
        $fields = array_values(array_unique(array_map('strval', $fields)));
        sort($fields);
        $this->nights = $normalised;
        $this->fields = $fields;
    }
}
