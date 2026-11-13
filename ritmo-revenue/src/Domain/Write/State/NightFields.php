<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\State;

use InvalidArgumentException;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\Num;

/**
 * The values one room night holds in one external system, by ChangeField name: what was read,
 * what was sent, what a write should leave. A missing field is "not set" (no date rule at all is
 * the empty map). Immutable.
 *
 * Numbers are compared within 1e-6 (percentages and stays are whole numbers; amounts are cents at
 * most); texts exactly. The reason text of a date rule is never part of it: the pricing screen
 * copies the reason of the last rule (docs/VERIFY.md, section 5).
 */
final class NightFields
{
    private const EPSILON = 1e-6;

    /** @var array<string, int|float|string> sorted by field name */
    private readonly array $fields;

    /**
     * @param array<string, int|float|string|null> $fields null leaves the field out
     *
     * @throws InvalidArgumentException on an unknown field or a value that is not a scalar
     */
    public function __construct(array $fields = [])
    {
        $clean = [];
        foreach ($fields as $name => $value) {
            if (!is_string($name) || !ChangeField::isKnown($name)) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s".', (string) $name));
            }
            if ($value === null) {
                continue;
            }
            if (!is_int($value) && !is_float($value) && !is_string($value)) {
                throw new InvalidArgumentException(sprintf('The value of "%s" must be a number or a text.', $name));
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException(sprintf('The value of "%s" must be finite.', $name));
            }
            $clean[$name] = self::normalize($value);
        }
        ksort($clean);
        $this->fields = $clean;
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * The date rule of the pricing system as read; null (no rule) is the empty map.
     */
    public static function fromOverride(?DateOverride $override): self
    {
        if ($override === null) {
            return self::none();
        }

        return new self([
            ChangeField::FLOOR_PCT => $override->minPercent,
            ChangeField::CEILING_PCT => $override->maxPercent,
            ChangeField::MIN_STAY => $override->minStay,
            ChangeField::CHECK_IN_MASK => $override->checkinMask,
            ChangeField::CHECK_OUT_MASK => $override->checkoutMask,
            ChangeField::PRICE => $override->price,
            ChangeField::PRICE_TYPE => $override->priceType,
            ChangeField::FLOOR_FIXED => $override->minFixed,
            ChangeField::CEILING_FIXED => $override->maxFixed,
        ]);
    }

    /**
     * A night of the PMS calendar as read, with the channel limits when the connector reads
     * them (channel => limit as text, null when lifted).
     *
     * @param array<string, string|null> $channelLimits
     */
    public static function fromInventory(?InventoryNight $night, array $channelLimits = []): self
    {
        if ($night === null) {
            return self::none();
        }
        $fields = [
            ChangeField::PMS_MIN_STAY => $night->minStay,
            ChangeField::PMS_MAX_STAY => $night->maxStay,
            ChangeField::PMS_RESTRICTION => $night->restriction->value,
            ChangeField::PMS_AVAILABLE => $night->available,
        ];
        foreach ($channelLimits as $channel => $limit) {
            $fields[ChangeField::channelLimit((string) $channel)] = $limit;
        }

        return new self($fields);
    }

    /**
     * @return array<string, int|float|string>
     */
    public function all(): array
    {
        return $this->fields;
    }

    public function get(string $field): int|float|string|null
    {
        return $this->fields[$field] ?? null;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * The values with $sent written over them: the fields sent replace, the others stay (how the
     * pricing system merges a write into the date rule).
     */
    public function merge(self $sent): self
    {
        return new self($sent->fields + $this->fields);
    }

    /**
     * Only the fields of one system.
     */
    public function of(Provider $provider): self
    {
        return new self(array_filter($this->fields, static fn (string $f): bool => ChangeField::providerOf($f) === $provider, ARRAY_FILTER_USE_KEY));
    }

    /**
     * Only the named fields.
     *
     * @param list<string> $names
     */
    public function only(array $names): self
    {
        return new self(array_intersect_key($this->fields, array_flip($names)));
    }

    /**
     * @param list<string> $names
     */
    public function without(array $names): self
    {
        return new self(array_diff_key($this->fields, array_flip($names)));
    }

    /**
     * The fields whose value differs between the two maps (set in one only, or another value).
     *
     * @return list<string> sorted
     */
    public function differs(self $other): array
    {
        $names = array_unique([...array_keys($this->fields), ...array_keys($other->fields)]);
        sort($names);

        return array_values(array_filter($names, fn (string $f): bool => !self::same($this->get($f), $other->get($f))));
    }

    public function equals(self $other): bool
    {
        return $this->differs($other) === [];
    }

    /**
     * A stable digest of the values, for comparing observations cheaply.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode($this->fields, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    public static function same(int|float|string|null $a, int|float|string|null $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_string($a) || is_string($b)) {
            return $a === $b;
        }

        return abs($a - $b) <= self::EPSILON;
    }

    private static function normalize(int|float|string $value): int|float|string
    {
        if (is_float($value) && abs($value) < PHP_INT_MAX && abs($value - Num::roundInt($value)) <= 1e-9) {
            return Num::roundInt($value);
        }

        return $value;
    }
}
