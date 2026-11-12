<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use InvalidArgumentException;

/**
 * A room type of a property: a group of interchangeable units sold under one listing.
 */
final class RoomType
{
    /**
     * @param string       $propertyId   id of the property it belongs to
     * @param string       $code         stable short key, unique per site
     * @param int          $units        number of sellable units
     * @param string       $pmsRef       id of the room in the PMS; empty while not mapped
     * @param string       $pricingRef   id of the listing in the pricing system; empty while not mapped
     * @param float        $base         base price of the listing (sent plan)
     * @param float        $min          minimum price of the listing (sent plan)
     * @param float        $max          maximum price of the listing (sent plan)
     * @param float        $referenceMin the owner's reference minimum; the safety limit is a fraction of it
     * @param list<string> $tags         slugs used by rules to select rooms ("tub", "view")
     *
     * @throws InvalidArgumentException on any invalid value
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly string $code,
        public readonly string $name,
        public readonly int $units,
        public readonly string $pmsRef,
        public readonly string $pricingRef,
        public readonly float $base,
        public readonly float $min,
        public readonly float $max,
        public readonly float $referenceMin,
        public readonly array $tags = [],
    ) {
        if (!Identifier::isCode($propertyId)) {
            throw new InvalidArgumentException(sprintf('Invalid property id "%s".', $propertyId));
        }
        if (!Identifier::isCode($code)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $code));
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('A room type needs a name.');
        }
        if ($units < 1) {
            throw new InvalidArgumentException('A room type needs at least one unit.');
        }
        foreach (['base' => $base, 'min' => $min, 'max' => $max, 'referenceMin' => $referenceMin] as $field => $amount) {
            if (!is_finite($amount) || $amount <= 0) {
                throw new InvalidArgumentException(sprintf('Room type "%s" must be greater than zero.', $field));
            }
        }
        if ($max < $min) {
            throw new InvalidArgumentException('Room type "max" must not be lower than "min".');
        }
        if ($tags !== array_values(array_unique($tags))) {
            throw new InvalidArgumentException('Room type tags must be a list without repeats.');
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || !Identifier::isSlug($tag)) {
                throw new InvalidArgumentException('Room type tags must be lowercase slugs.');
            }
        }
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * True when the room has every tag of the list. An empty list matches every room.
     *
     * @param list<string> $tags
     */
    public function hasAllTags(array $tags): bool
    {
        return array_diff($tags, $this->tags) === [];
    }

    /**
     * Builds a room type from its preset/storage form (snake_case keys).
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when a required key is missing or a value is invalid
     */
    public static function fromArray(array $data): self
    {
        foreach (['property_id', 'code', 'name'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new InvalidArgumentException(sprintf('Room type is missing the text field "%s".', $key));
            }
        }
        if (!isset($data['units']) || !is_int($data['units'])) {
            throw new InvalidArgumentException('Room type is missing the whole number "units".');
        }
        foreach (['base', 'min', 'max', 'reference_min'] as $key) {
            if (!isset($data[$key]) || !(is_int($data[$key]) || is_float($data[$key]))) {
                throw new InvalidArgumentException(sprintf('Room type is missing the number "%s".', $key));
            }
        }
        foreach (['pms_ref', 'pricing_ref'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new InvalidArgumentException(sprintf('Room type "%s" must be text.', $key));
            }
        }
        $tags = $data['tags'] ?? [];
        if (!is_array($tags)) {
            throw new InvalidArgumentException('Room type "tags" must be a list.');
        }

        return new self(
            $data['property_id'],
            $data['code'],
            $data['name'],
            $data['units'],
            $data['pms_ref'] ?? '',
            $data['pricing_ref'] ?? '',
            (float) $data['base'],
            (float) $data['min'],
            (float) $data['max'],
            (float) $data['reference_min'],
            array_values($tags),
        );
    }

    /**
     * @return array{property_id: string, code: string, name: string, units: int, pms_ref: string, pricing_ref: string, base: float, min: float, max: float, reference_min: float, tags: list<string>}
     */
    public function toArray(): array
    {
        return [
            'property_id' => $this->propertyId,
            'code' => $this->code,
            'name' => $this->name,
            'units' => $this->units,
            'pms_ref' => $this->pmsRef,
            'pricing_ref' => $this->pricingRef,
            'base' => $this->base,
            'min' => $this->min,
            'max' => $this->max,
            'reference_min' => $this->referenceMin,
            'tags' => $this->tags,
        ];
    }
}
