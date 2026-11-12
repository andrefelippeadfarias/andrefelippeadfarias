<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * A last-minute discount under the listing minimum, without an owner order: allowed in the rooms
 * listed, a few days before the night, down to a share under the minimum, on a night with units
 * free and not mostly sold (tonight, mostly sold, only with a warning), and in the rooms listed as
 * "last unit only" only for the last unit. Off by default.
 *
 * Reference: Regra I of validar_plano.Validador.validar_data (regra_i, regra_i_dias,
 * regra_i_desconto_max, the 70% of the night sold, demanda alta).
 */
final class LastMinuteRule
{
    /**
     * @param list<string> $rooms             rooms the rule applies to
     * @param list<string> $lastUnitOnlyRooms rooms where it applies to the last free unit only
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly array $rooms = [],
        public readonly int $maxDaysOut = 3,
        public readonly float $maxDiscount = 0.2,
        public readonly float $maxSoldShare = 0.7,
        public readonly array $lastUnitOnlyRooms = [],
    ) {
    }

    /**
     * True when the rule may take this room under its listing minimum at all.
     */
    public function coversRoom(string $roomCode): bool
    {
        return $this->enabled && in_array($roomCode, $this->rooms, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function read(ConfigReader $reader, array $data, string $path): self
    {
        $rooms = [];
        foreach ($reader->items($data['rooms'], "$path.rooms") as $i => $room) {
            $rooms[] = $reader->string($room, "$path.rooms.$i");
        }
        $lastUnit = [];
        foreach ($reader->items($data['last_unit_only_rooms'], "$path.last_unit_only_rooms") as $i => $room) {
            $lastUnit[] = $reader->string($room, "$path.last_unit_only_rooms.$i");
        }

        return new self(
            $reader->bool($data['enabled'], "$path.enabled"),
            $rooms,
            $reader->int($data['max_days_out'], "$path.max_days_out"),
            $reader->float($data['max_discount'], "$path.max_discount"),
            $reader->float($data['max_sold_share'], "$path.max_sold_share"),
            $lastUnit,
        );
    }

    /**
     * @return list<Field>
     */
    public static function fields(string $path, string $roomPattern): array
    {
        return [
            new Field($path, FieldType::Group, 'Last-minute discount', 'A floor under the listing minimum without an owner order, a few days before a night that still has units free.'),
            new Field("$path.enabled", FieldType::Boolean, 'Allow it', 'Off: a floor under the listing minimum always needs an owner order.', false),
            new Field("$path.rooms", FieldType::ListOf, 'Rooms', 'The rooms that may go under their listing minimum.', [], max: 200, unique: true),
            new Field("$path.rooms.*", FieldType::Text, 'Room', min: 1, max: 40, pattern: $roomPattern),
            new Field("$path.max_days_out", FieldType::Integer, 'Days before the night', 'Only this many days before the night, or fewer.', 3, min: 0, max: 30, unit: Field::UNIT_DAYS),
            new Field("$path.max_discount", FieldType::Number, 'Deepest discount', 'Share of the listing minimum the floor may go under it.', 0.2, min: 0, max: 0.9, unit: Field::UNIT_RATIO),
            new Field("$path.max_sold_share", FieldType::Number, 'Most sold share', 'Not on a night with more than this share of the units sold (tonight only with a warning).', 0.7, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field("$path.last_unit_only_rooms", FieldType::ListOf, 'Last unit only', 'Rooms in high demand: the discount only for the last free unit.', [], max: 200, unique: true),
            new Field("$path.last_unit_only_rooms.*", FieldType::Text, 'Room', min: 1, max: 40, pattern: $roomPattern),
        ];
    }
}
