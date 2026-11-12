<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Support\Days;

/**
 * The market ruler of one night: its night-type label, the public prices of each market series at
 * stored percentiles, the public factor measured per property and the reference sent price of
 * each room (the price in force when a strategy was set up).
 *
 * Lookups are exact: a series or percentile that is not stored has no value (no interpolation).
 *
 * Reference: dados/regua_dono.json and plano_dono.ancora / plano_dono.fator.
 */
final class RulerNight
{
    public readonly DateTimeImmutable $night;

    /**
     * @param array<string, array<string, float>> $series        series => percentile key ("40") => public price
     * @param array<string, float>                $publicFactors property id => public / sent measured on the night
     * @param array<string, float>                $referenceSent room code => reference sent price
     */
    public function __construct(
        DateTimeImmutable $night,
        public readonly string $type,
        public readonly array $series,
        public readonly array $publicFactors = [],
        public readonly array $referenceSent = [],
    ) {
        if (preg_match(Identifier::SLUG_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid night-type label "%s".', $type));
        }
        foreach ($publicFactors as $factor) {
            if (!is_float($factor) || $factor <= 0) {
                throw new InvalidArgumentException('Public factors must be greater than zero.');
            }
        }
        $this->night = Days::normalize($night);
    }

    /**
     * The stored public price of a series at a percentile, or null.
     */
    public function valueAt(string $series, float $percentile): ?float
    {
        return $this->series[$series][self::key($percentile)] ?? null;
    }

    public function publicFactor(string $propertyId): ?float
    {
        return $this->publicFactors[$propertyId] ?? null;
    }

    /**
     * The reference sent price of the room; zero or missing means none.
     */
    public function referenceSent(string $room): ?float
    {
        $value = $this->referenceSent[$room] ?? null;

        return $value !== null && $value != 0.0 ? $value : null;
    }

    /**
     * A night from stored data: type, series, public_factor, reference_sent.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException on a malformed row
     */
    public static function fromArray(string $night, array $row): self
    {
        $series = [];
        foreach ((array) ($row['series'] ?? []) as $name => $points) {
            foreach ((array) $points as $percentile => $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new InvalidArgumentException(sprintf('Ruler %s: the value of %s at %s is not a number.', $night, $name, $percentile));
                }
                $series[(string) $name][self::key((float) $percentile)] = (float) $value;
            }
        }

        return new self(
            Days::date($night),
            (string) ($row['type'] ?? ''),
            $series,
            array_map('floatval', (array) ($row['public_factor'] ?? [])),
            array_map('floatval', (array) ($row['reference_sent'] ?? [])),
        );
    }

    private static function key(float $percentile): string
    {
        return $percentile == (int) $percentile ? (string) (int) $percentile : (string) $percentile;
    }
}
