<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use InvalidArgumentException;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;

/**
 * Reads a preset (file, JSON text or array) into a validated Preset.
 *
 * Missing rule fields that have a default are filled in, so a preset exported by an older
 * version still loads; everything else is validated strictly and every error is reported with
 * its path ("properties.0.room_types.2.units", "rules.pace.band").
 */
final class PresetLoader
{
    private const KEYS = ['format', 'format_version', 'name', 'description', 'properties', 'rules'];

    private const PROPERTY_KEYS = ['id', 'name', 'timezone', 'currency', 'mode', 'tier', 'occupancy_goals', 'room_types'];

    private const ROOM_TYPE_KEYS = ['code', 'name', 'units', 'pms_ref', 'pricing_ref', 'base', 'min', 'max', 'reference_min', 'tags'];

    /** @var list<ValidationError> */
    private array $errors = [];

    /**
     * @throws InvalidConfig when the file cannot be read or the preset is invalid
     */
    public function fromFile(string $path): Preset
    {
        $json = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidConfig('preset', [new ValidationError('', ValidationError::FORMAT, 'the preset file cannot be read')]);
        }

        return $this->fromJson($json);
    }

    /**
     * @throws InvalidConfig when the text is not a JSON object or the preset is invalid
     */
    public function fromJson(string $json): Preset
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new InvalidConfig('preset', [new ValidationError('', ValidationError::FORMAT, 'the preset is not a JSON object')]);
        }

        return $this->fromArray($data);
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @throws InvalidConfig with every error found
     */
    public function fromArray(array $data): Preset
    {
        $this->errors = [];
        $this->checkEnvelope($data);
        [$properties, $roomTypes] = $this->readProperties($data['properties'] ?? []);
        $rules = $this->readRules($data['rules'] ?? null);
        if ($rules !== null && $this->errors === []) {
            $this->checkReferences($properties, $roomTypes, $rules);
        }
        if ($rules === null || $this->errors !== []) {
            throw new InvalidConfig('preset', $this->errors);
        }

        return new Preset((string) ($data['name'] ?? ''), (string) ($data['description'] ?? ''), $properties, $roomTypes, $rules);
    }

    private function add(string $path, string $code, string $message): void
    {
        $this->errors[] = new ValidationError($path, $code, $message);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function checkEnvelope(array $data): void
    {
        $this->rejectUnknownKeys('', $data, self::KEYS);
        if (($data['format'] ?? null) !== Preset::FORMAT) {
            $this->add('format', ValidationError::FORMAT, sprintf('must be "%s"', Preset::FORMAT));
        }
        if (($data['format_version'] ?? null) !== Preset::FORMAT_VERSION) {
            $this->add('format_version', ValidationError::FORMAT, sprintf('must be %d', Preset::FORMAT_VERSION));
        }
        foreach (['name', 'description'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                $this->add($key, ValidationError::TYPE, 'must be text');
            }
        }
    }

    /**
     * @param array<int|string, mixed> $row
     * @param list<string>             $known
     */
    private function rejectUnknownKeys(string $path, array $row, array $known): void
    {
        foreach (array_diff(array_keys($row), $known) as $unknown) {
            $this->add($path === '' ? (string) $unknown : $path . '.' . $unknown, ValidationError::UNKNOWN, 'is not a known field');
        }
    }

    /**
     * @return array{list<Property>, list<RoomType>}
     */
    private function readProperties(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->add('properties', ValidationError::TYPE, 'must be a list');

            return [[], []];
        }

        $properties = [];
        $roomTypes = [];
        foreach ($rows as $index => $row) {
            $path = 'properties.' . $index;
            if (!is_array($row)) {
                $this->add($path, ValidationError::TYPE, 'must be a group of fields');
                continue;
            }
            $this->rejectUnknownKeys($path, $row, self::PROPERTY_KEYS);
            $rooms = $row['room_types'] ?? [];
            unset($row['room_types']);
            try {
                $property = Property::fromArray($row);
            } catch (InvalidArgumentException $e) {
                $this->add($path, ValidationError::FORMAT, $e->getMessage());
                continue;
            }
            if (isset($properties[$property->id])) {
                $this->add($path . '.id', ValidationError::UNIQUE, 'repeats a property id');
                continue;
            }
            $properties[$property->id] = $property;
            foreach ($this->readRoomTypes($property->id, $path . '.room_types', $rooms) as $roomType) {
                if (isset($roomTypes[$roomType->code])) {
                    $this->add($path . '.room_types', ValidationError::UNIQUE, sprintf('repeats the room type code "%s"', $roomType->code));
                    continue;
                }
                $roomTypes[$roomType->code] = $roomType;
            }
        }

        return [array_values($properties), array_values($roomTypes)];
    }

    /**
     * @return list<RoomType>
     */
    private function readRoomTypes(string $propertyId, string $path, mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->add($path, ValidationError::TYPE, 'must be a list');

            return [];
        }

        $roomTypes = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $this->add($path . '.' . $index, ValidationError::TYPE, 'must be a group of fields');
                continue;
            }
            $this->rejectUnknownKeys($path . '.' . $index, $row, self::ROOM_TYPE_KEYS);
            try {
                $roomTypes[] = RoomType::fromArray(['property_id' => $propertyId] + $row);
            } catch (InvalidArgumentException $e) {
                $this->add($path . '.' . $index, ValidationError::FORMAT, $e->getMessage());
            }
        }

        return $roomTypes;
    }

    private function readRules(mixed $rules): ?RuleSet
    {
        if (!is_array($rules)) {
            $this->add('rules', ValidationError::REQUIRED, 'is required');

            return null;
        }

        try {
            return RuleSet::fromArray(Schema::ruleSet()->withDefaults($rules));
        } catch (InvalidConfig $e) {
            foreach ($e->errors() as $error) {
                $this->add('rules.' . $error->path, $error->code, $error->message);
            }

            return null;
        }
    }

    /**
     * Rules and properties must agree: a tier or a factor table that points at nothing is a typo.
     *
     * @param list<Property> $properties
     * @param list<RoomType> $roomTypes
     */
    private function checkReferences(array $properties, array $roomTypes, RuleSet $rules): void
    {
        $tiers = $rules->get('pace.target_percentile');
        $propertyIds = [];
        foreach ($properties as $index => $property) {
            $propertyIds[] = $property->id;
            if (!isset($tiers[$property->tier])) {
                $this->add("properties.$index.tier", ValidationError::REFERENCE, sprintf('"%s" is not a tier of pace.target_percentile', $property->tier));
            }
        }
        if ($properties === []) {
            return;
        }

        foreach (array_keys($rules->get('price_factors.properties')) as $key) {
            if ($key !== RuleSetFields::ANY_PROPERTY && !in_array((string) $key, $propertyIds, true)) {
                $this->add('rules.price_factors.properties.' . $key, ValidationError::REFERENCE, 'is not the id of a property of this preset');
            }
        }
        $codes = array_map(static fn (RoomType $roomType): string => $roomType->code, $roomTypes);
        foreach ($rules->get('price_factors.room_overrides') as $index => $override) {
            if (!in_array($override['room'], $codes, true)) {
                $this->add("rules.price_factors.room_overrides.$index.room", ValidationError::REFERENCE, 'is not the code of a room type of this preset');
            }
        }
    }
}
