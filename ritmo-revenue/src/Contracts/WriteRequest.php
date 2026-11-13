<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * What one write request changes, as a connector describes it to the write authority before
 * anything leaves the site: the operation, the targets (listing ids of the pricing system or room
 * ids of the PMS) with the nights of each, the names of the fields sent and the entries of the
 * body with their values (entriesOf(): one canonical text per date rule, range or listing value),
 * so a token never carries other values than the ones the gate checked.
 *
 * Nights are YYYY-MM-DD keys, sorted and unique; a recalculation or a listing change has none.
 */
final class WriteRequest
{
    private const JSON = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var array<string, list<string>> target => nights */
    public readonly array $nights;

    /** @var list<string> */
    public readonly array $fields;

    /** @var list<string> */
    public readonly array $entries;

    /**
     * @param array<string, list<string>> $nights  target => nights (YYYY-MM-DD)
     * @param list<string>                $fields  names of the fields sent
     * @param list<string>                $entries the body's entries with their values (entriesOf())
     *
     * @throws InvalidArgumentException on no target, an empty target or a malformed night
     */
    public function __construct(public readonly WriteOperation $operation, array $nights, array $fields = [], array $entries = [])
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
        $this->entries = array_values(array_map('strval', $entries));
    }

    /**
     * The entries of what a write sends, one canonical text each: the elements of a list (date
     * rules, PMS ranges, dates), or each name and value of a map (listing values). Keys are sorted
     * at every level, types are kept ("46" is not 46).
     *
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    public static function entriesOf(array $payload): array
    {
        if ($payload === []) {
            return [];
        }
        $entries = array_is_list($payload)
            ? array_map(static fn (mixed $entry): mixed => self::canonical($entry), $payload)
            : array_map(static fn (string|int $name, mixed $value): array => [(string) $name, self::canonical($value)], array_keys($payload), array_values($payload));

        return array_map(static fn (mixed $entry): string => json_encode($entry, self::JSON), $entries);
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
