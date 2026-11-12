<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations\Golden;

use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

/**
 * The parity fixture of the relations (tests/fixtures/presets/parity-relations.json) with the rooms
 * of the M1 parity preset, and the readers of the golden cases of tests/fixtures/golden/m2/relations.
 */
final class ParityRelations
{
    public const GOLDEN = 'golden/m2/relations/';

    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return Fixtures::json('presets/parity-relations.json');
    }

    /**
     * The relations configuration, with the patches of a case applied ({relation id: {dotted param: value}}).
     *
     * @param array<string, array<string, mixed>> $patch
     */
    public static function config(array $patch = [], ?int $scanDays = null): RelationsConfig
    {
        $data = self::data();
        unset($data['_doc']);
        foreach ($data['relations']['items'] as &$item) {
            foreach ($patch[$item['id']] ?? [] as $path => $value) {
                $target = &$item['params'];
                foreach (explode('.', $path) as $segment) {
                    $target = &$target[$segment];
                }
                $target = $value;
                unset($target);
            }
        }
        unset($item);
        if ($scanDays !== null) {
            $data['relations']['scan_horizon_days'] = $scanDays;
        }

        return RelationsConfig::fromArray($data);
    }

    /**
     * The room types of the parity preset, with a case's patches ({code: {base|max: value}}) and removals.
     *
     * @param array<string, array<string, float|int>> $patch
     * @param list<string>                             $removed
     *
     * @return list<RoomType>
     */
    public static function rooms(array $patch = [], array $removed = []): array
    {
        $rooms = [];
        foreach (Fixtures::parityPreset()->roomTypes as $room) {
            if (in_array($room->code, $removed, true)) {
                continue;
            }
            $data = array_merge($room->toArray(), array_map('floatval', $patch[$room->code] ?? []));
            $rooms[] = RoomType::fromArray($data);
        }

        return $rooms;
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function graph(array $input): RelationGraph
    {
        $config = self::config($input['relations_patch'] ?? [], $input['scan_days'] ?? null);

        return new RelationGraph($config->relations, self::rooms($input['rooms_patch'] ?? [], $input['rooms_removed'] ?? []));
    }

    /**
     * A golden calendar ({room: {night: {price, sold, total, min, max}}}) as price nights.
     *
     * @param array<string, array<string, array<string, mixed>>> $calendar
     *
     * @return array<string, list<PriceNight>>
     */
    public static function calendar(array $calendar): array
    {
        $out = [];
        foreach ($calendar as $room => $nights) {
            foreach ($nights as $night => $row) {
                $out[$room][] = new PriceNight(
                    Days::date($night),
                    (float) $row['price'],
                    $row['min'] === null ? null : (float) $row['min'],
                    $row['max'] === null ? null : (float) $row['max'],
                    null,
                    $row['sold'],
                    $row['total'],
                );
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: string, input: array<string, mixed>, expected: mixed}>
     */
    public static function cases(string $function): array
    {
        return Fixtures::json(self::GOLDEN . $function . '.json')['cases'];
    }

    /**
     * Data provider form: case name => [input, expected, whole case].
     *
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function provide(string $function): array
    {
        $cases = [];
        foreach (self::cases($function) as $case) {
            $cases[$case['name']] = [$case['input'], $case['expected'], $case];
        }

        return $cases;
    }
}
