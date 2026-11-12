<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;

/**
 * A complete, validated configuration: properties, their room types and the rule set.
 *
 * It is what an import produces and what an export writes. The neutral preset shipped with the
 * plugin has no properties (the setup wizard creates them); a customer export has all three parts.
 */
final class Preset
{
    public const FORMAT = 'ritmo-revenue-preset';

    public const FORMAT_VERSION = 1;

    /**
     * @param list<Property> $properties
     * @param list<RoomType> $roomTypes  of every property, in file order
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $roomTypes,
        public readonly RuleSet $rules,
    ) {
    }

    public function property(string $id): ?Property
    {
        foreach ($this->properties as $property) {
            if ($property->id === $id) {
                return $property;
            }
        }

        return null;
    }

    public function roomType(string $code): ?RoomType
    {
        foreach ($this->roomTypes as $roomType) {
            if ($roomType->code === $code) {
                return $roomType;
            }
        }

        return null;
    }

    /**
     * @return list<RoomType>
     */
    public function roomTypesOf(string $propertyId): array
    {
        return array_values(array_filter(
            $this->roomTypes,
            static fn (RoomType $roomType): bool => $roomType->propertyId === $propertyId
        ));
    }

    /**
     * The preset in its file form; PresetLoader::fromArray() reads it back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $properties = [];
        foreach ($this->properties as $property) {
            $rooms = [];
            foreach ($this->roomTypesOf($property->id) as $roomType) {
                $room = $roomType->toArray();
                unset($room['property_id']);
                $rooms[] = $room;
            }
            $properties[] = $property->toArray() + ['room_types' => $rooms];
        }

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'name' => $this->name,
            'description' => $this->description,
            'properties' => $properties,
            'rules' => $this->rules->toArray(),
        ];
    }
}
