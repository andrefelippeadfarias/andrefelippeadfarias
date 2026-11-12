<?php

declare(strict_types=1);

namespace Ritmo\Application\Setup;

use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\PresetLoader;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\AnchorBuilder;
use Ritmo\Domain\Market\PriceSample;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\PriceSampleRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * The minimal setup: create a property, map its room types to the PMS and the pricing system,
 * import a preset and record competitor prices. Inputs come from forms, so every value is checked
 * here and refused with a sentence safe to show. Nothing here leaves shadow mode.
 */
final class SetupService
{
    public function __construct(
        private readonly Database $db,
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly PriceSampleRepository $samples,
        private readonly MarketAnchorRepository $anchors,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input id, name, timezone, currency and optionally tier, as typed in the form
     *
     * @throws InvalidArgumentException when a value is missing or wrong, or the id is taken
     */
    public function createProperty(array $input): Property
    {
        $id = self::text($input, 'id');
        if (!Identifier::isCode($id)) {
            throw new InvalidArgumentException('The property id is a short code of letters, digits, "-" and "_" (up to 32).');
        }
        if ($this->properties->find($id) !== null) {
            throw new InvalidArgumentException('A property with this id already exists.');
        }
        $name = self::text($input, 'name');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('The property needs a name of up to 100 characters.');
        }
        $timezone = self::text($input, 'timezone');
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Choose the timezone of the property from the list.');
        }
        $currency = strtoupper(self::text($input, 'currency'));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('The currency is a three-letter code such as EUR, USD or BRL.');
        }
        $tier = self::text($input, 'tier') === '' ? 'standard' : self::text($input, 'tier');
        if (!Identifier::isSlug($tier)) {
            throw new InvalidArgumentException('The tier is a lowercase name such as "standard" or "premium".');
        }

        $property = new Property($id, $name, $timezone, $currency, PropertyMode::Shadow, [], $tier);
        $this->properties->save($property);

        return $property;
    }

    /**
     * Creates or replaces a room type and its mapping to the PMS room and the pricing listing.
     *
     * @param array<string, mixed> $input property_id, code, name, units, pms_ref, pricing_ref, base, min, max, reference_min, tags (comma separated)
     *
     * @throws InvalidArgumentException when a value is missing or wrong
     */
    public function saveRoomType(array $input): RoomType
    {
        $propertyId = self::text($input, 'property_id');
        if ($this->properties->find($propertyId) === null) {
            throw new InvalidArgumentException('Choose an existing property for the room type.');
        }
        $tags = array_values(array_filter(array_map('trim', explode(',', strtolower(self::text($input, 'tags')))), static fn (string $tag): bool => $tag !== ''));
        $room = RoomType::fromArray([
            'property_id' => $propertyId,
            'code' => self::text($input, 'code'),
            'name' => self::text($input, 'name'),
            'units' => self::wholeNumber($input, 'units'),
            'pms_ref' => self::text($input, 'pms_ref'),
            'pricing_ref' => self::text($input, 'pricing_ref'),
            'base' => self::amount($input, 'base'),
            'min' => self::amount($input, 'min'),
            'max' => self::amount($input, 'max'),
            'reference_min' => self::amount($input, 'reference_min'),
            'tags' => $tags,
        ]);
        $this->roomTypes->save($room);

        return $room;
    }

    /**
     * Imports a preset file: its rules become a new rule set version and its properties and room
     * types are created or replaced, every property in shadow mode whatever the file says.
     *
     * @return array{properties: int, room_types: int, rules_version: int}
     *
     * @throws InvalidConfig            listing every error of the file
     * @throws InvalidArgumentException when the text is not a preset
     */
    public function importPreset(string $json, int $userId = 0): array
    {
        $preset = (new PresetLoader())->fromJson($json);

        return $this->db->transaction(function () use ($preset, $userId): array {
            $version = $this->ruleSets->save($preset->rules, 'Imported preset: ' . mb_substr($preset->name, 0, 100), $userId);
            foreach ($preset->properties as $property) {
                $this->properties->save(Property::fromArray(['mode' => PropertyMode::Shadow->value] + $property->toArray()));
            }
            foreach ($preset->roomTypes as $room) {
                $this->roomTypes->save($room);
            }

            return ['properties' => count($preset->properties), 'room_types' => count($preset->roomTypes), 'rules_version' => $version];
        });
    }

    /**
     * Records competitor prices pasted as CSV (see SampleCsv) and rebuilds the market anchor of
     * each night they cover. Each paste is a full picture of the market for its nights: the
     * anchor of a night is computed from the prices of this paste only, and replaces the anchor
     * in force by the rule of AnchorBuilder::replaces().
     *
     * @return array{samples: int, nights: int, anchors: int}
     *
     * @throws InvalidArgumentException when the property does not exist or a line cannot be read
     */
    public function importSamples(string $propertyId, string $csv): array
    {
        $property = $this->properties->find($propertyId) ?? throw new InvalidArgumentException('Choose an existing property for the prices.');
        $rows = SampleCsv::parse($csv);
        $now = $this->clock->now();
        $nights = array_map(static fn (array $row) => $row['night'], $rows);
        $from = min($nights);
        $to = max($nights);

        return $this->db->transaction(function () use ($property, $rows, $now, $from, $to): array {
            foreach ($rows as $row) {
                $this->samples->add($property->id, 0, $row['night'], $row['price'], $now, 'csv');
            }
            $fresh = array_map(
                static fn (array $stored): PriceSample => new PriceSample($stored['night'], $stored['price'], $stored['captured_at']),
                $this->samples->forNights($property->id, $from, $to, $now)
            );
            $builder = new AnchorBuilder($this->ruleSets->current() ?? RuleSet::defaults());
            $built = $builder->build($fresh);
            $merged = $builder->merge($this->anchors->range($property->id, $from, $to), $built, $this->clock->today($property->timezone));
            $replaced = array_filter($built, static fn (MarketAnchor $anchor, string $night): bool => $merged[$night] === $anchor, ARRAY_FILTER_USE_BOTH);
            $this->anchors->saveMany($property->id, array_values($replaced));

            return ['samples' => count($rows), 'nights' => count($built), 'anchors' => count($replaced)];
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function wholeNumber(array $input, string $key): int
    {
        $value = self::text($input, $key);
        if (preg_match('/^\d{1,4}$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" must be a whole number.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function amount(array $input, string $key): float
    {
        $value = self::text($input, $key);
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" must be an amount such as 480 or 480.50.', $key));
        }

        return (float) $value;
    }
}
