<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Protection;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Settings of the sold-out protection.
 *
 * Reference: pace.protecao_esgotada and its caller in pace._plano.
 */
final class ProtectionConfig
{
    public const ROOT = 'soldout';

    /**
     * @param list<string> $retiredFloorMarkers    a live override whose reason contains one of these was written by a
     *                                             retired rule: its floor is not kept when the ceiling is released
     * @param list<string> $retiredFloorExemptions ...unless the reason also contains one of these
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly float $percentile = 90.0,
        public readonly bool $useTargetOrderFloor = true,
        public readonly bool $useRelationBounds = true,
        public readonly bool $keepLiveFloorOnRelease = true,
        public readonly array $retiredFloorMarkers = [],
        public readonly array $retiredFloorExemptions = [],
        public readonly ?int $lightRunMaxDays = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "soldout" section; missing keys take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $p = $d['protection'];
        $texts = static function (string $key) use ($reader, $p): array {
            $out = [];
            foreach ($reader->items($p[$key], "soldout.protection.$key") as $i => $text) {
                $out[] = $reader->string($text, "soldout.protection.$key.$i");
            }

            return $out;
        };
        $config = new self(
            $reader->bool($p['enabled'], 'soldout.protection.enabled'),
            $reader->float($p['percentile'], 'soldout.protection.percentile'),
            $reader->bool($p['use_target_order_floor'], 'soldout.protection.use_target_order_floor'),
            $reader->bool($p['use_relation_bounds'], 'soldout.protection.use_relation_bounds'),
            $reader->bool($p['keep_live_floor_on_release'], 'soldout.protection.keep_live_floor_on_release'),
            $texts('retired_floor_markers'),
            $texts('retired_floor_exemptions'),
            $reader->nullableInt($d['light_run_max_days'], 'soldout.light_run_max_days'),
        );
        $reader->finish('soldout');

        return $config;
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('soldout', FieldType::Group, 'Sold-out nights', 'What the engine does when every unit of a room type is sold.'),
            new Field('soldout.protection', FieldType::Group, 'Sold-out protection', 'A floor high in the market, so a cancellation never reopens the night cheap.'),
            new Field('soldout.protection.enabled', FieldType::Boolean, 'Protect sold-out nights', 'Raise the floor of sold-out nights and release their ceiling.', true),
            new Field('soldout.protection.percentile', FieldType::Number, 'Protection percentile', 'Market position of the protection floor.', 90.0, min: 25, max: 110),
            new Field('soldout.protection.use_target_order_floor', FieldType::Boolean, 'Honour order floor', 'Never protect below the floor of a target order on that night.', true),
            new Field('soldout.protection.use_relation_bounds', FieldType::Boolean, 'Honour room relations', 'Keep the premium floor and the band ceiling of the room.', true),
            new Field('soldout.protection.keep_live_floor_on_release', FieldType::Boolean, 'Keep a higher floor', 'When the ceiling must go, a floor in force above the protection floor stays; only the ceiling goes.', true),
            new Field('soldout.protection.retired_floor_markers', FieldType::ListOf, 'Retired rule markers', 'A floor written by a retired rule (its reason contains one of these) is not kept: it comes down to the protection floor.', [], max: 20),
            new Field('soldout.protection.retired_floor_markers.*', FieldType::Text, 'Marker', min: 1, max: 100),
            new Field('soldout.protection.retired_floor_exemptions', FieldType::ListOf, 'Not retired when', 'A reason that also contains one of these is not from a retired rule.', [], max: 20),
            new Field('soldout.protection.retired_floor_exemptions.*', FieldType::Text, 'Exemption', min: 1, max: 100),
            new Field('soldout.light_run_max_days', FieldType::Integer, 'Light-run reach (days)', 'In light runs protect only nights this close; empty = every changed night.', null, nullable: true, min: 0, max: 60, unit: Field::UNIT_DAYS),
        ];
    }
}
