<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Coherence\CoherenceContext;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Coherence\PlanDraft;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Support\Days;

/**
 * Two made-up properties for the unit tests of the relations.
 *
 * "inn": LOW (4 units, base 1173), HIGH (3, base 1480), MID (2, base 1590) and TOP (1, base 2000),
 * all tagged "tub". "lodge": SPA (5 units, base 1500) and SPA2 (2, base 1300) tagged "tub", and
 * PLAIN (4 units, base 1270) without a tag. Listing minimum 100 and maximum 5000 everywhere, so the
 * hard floors never get in the way. Rule set: schema defaults (Friday and Saturday are weekend
 * nights) plus a holiday period from 18 to 21 March 2027.
 */
final class RelationsSample
{
    /** A Monday. */
    public const TODAY = '2027-03-01';

    /** A Tuesday. */
    public const NIGHT = '2027-03-02';

    /** A Friday inside the holiday period. */
    public const HOLIDAY = '2027-03-19';

    public static function rules(): RuleSet
    {
        $data = Schema::ruleSet()->defaults();
        $data['calendar']['holidays'] = [
            ['name' => 'Fair', 'start' => '2027-03-18', 'end' => '2027-03-21', 'night_type' => 'holiday', 'min_stay' => null, 'floor' => null],
        ];

        return RuleSet::fromArray($data);
    }

    /**
     * @return list<RoomType>
     */
    public static function rooms(): array
    {
        $room = static fn (string $property, string $code, int $units, float $base, array $tags): RoomType => new RoomType($property, $code, $code . ' room', $units, '', '', $base, 100.0, 5000.0, 100.0, $tags);

        return [
            $room('inn', 'LOW', 4, 1173.0, ['tub']),
            $room('inn', 'HIGH', 3, 1480.0, ['tub']),
            $room('inn', 'MID', 2, 1590.0, ['tub']),
            $room('inn', 'TOP', 1, 2000.0, ['tub']),
            $room('lodge', 'SPA', 5, 1500.0, ['tub']),
            $room('lodge', 'PLAIN', 4, 1270.0, []),
            $room('lodge', 'SPA2', 2, 1300.0, ['tub']),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function relationData(string $id, string $type, string $target, array $params, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'property' => in_array($target, ['SPA', 'SPA2', 'PLAIN'], true) ? 'lodge' : 'inn',
            'type' => $type,
            'target' => $target,
            'params' => $params,
        ], $extra);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $extra
     */
    public static function relation(string $id, string $type, string $target, array $params, array $extra = []): Relation
    {
        return Relation::fromArray(self::relationData($id, $type, $target, $params, $extra));
    }

    /**
     * The switch and the band of PLAIN over the tub rooms of the lodge (as a small owner would set
     * them: 90% to 85.5% of the cheapest free tub room; at 80% of the tub units sold, the market).
     *
     * @return list<Relation>
     */
    public static function lodgeRelations(string $action = 'market_percentile'): array
    {
        return [
            self::relation('plain-switch', 'occupancy_switch', 'PLAIN', [
                'trigger' => ['set' => ['room_tags' => ['tub']], 'unit' => 'percent', 'threshold' => 80],
                'then' => ['kind' => $action] + ($action === 'market_percentile' ? ['by_night_group' => ['weekday' => ['segment' => 'all', 'percentile' => 40]]] : []),
                'cap_relation' => 'plain-band',
                'suspends' => ['plain-band'],
            ], ['priority' => 10, 'stages' => ['coherence', 'validate'], 'order_ref' => 'switch-order']),
            self::relation('plain-band', 'discount_band', 'PLAIN', [
                'set' => ['room_tags' => ['tub']], 'upper_percent' => 90, 'lower_percent' => 85.5,
                'reference_rounding_margin' => 15, 'lower_extra_amount' => 1, 'down_only_night_types' => ['holiday'],
            ], ['priority' => 20, 'stages' => ['coherence', 'validate'], 'order_ref' => 'band-order']),
        ];
    }

    /**
     * Calendar rows: room => night => [price, sold, floor in force?, ceiling in force?].
     *
     * @param array<string, array<string, array{0: float|int, 1: int, 2?: float|int|null, 3?: float|int|null}>> $rows
     *
     * @return array<string, list<PriceNight>>
     */
    public static function calendar(array $rows): array
    {
        $units = [];
        foreach (self::rooms() as $room) {
            $units[$room->code] = $room->units;
        }
        $calendar = [];
        foreach ($rows as $code => $nights) {
            foreach ($nights as $night => $row) {
                $min = isset($row[2]) ? (float) $row[2] : null;
                $max = isset($row[3]) ? (float) $row[3] : null;
                $calendar[$code][] = new PriceNight(Days::date($night), (float) $row[0], $min, $max, null, $row[1], $units[$code]);
            }
        }

        return $calendar;
    }

    /**
     * @param list<Relation>                                $relations
     * @param array<string, list<PriceNight>>               $calendar
     * @param array<string, float>                          $decided
     * @param list<DraftItem>                               $items
     * @param array<string, float>                          $strategyDecided
     * @param list<string>                                  $blocked
     */
    public static function context(
        array $relations,
        array $calendar,
        StubHooks $hooks,
        array $decided = [],
        array $items = [],
        ?RelationSettings $settings = null,
        array $strategyDecided = [],
        array $blocked = [],
    ): CoherenceContext {
        return new CoherenceContext(
            new RelationGraph($relations, self::rooms()),
            $settings ?? new RelationSettings(),
            new NightFacts(self::rooms(), $calendar, $decided, $strategyDecided, null, $blocked),
            $hooks->bundle(),
            new PlanDraft($items),
            new RelationReport(),
            Days::date(self::TODAY),
        );
    }
}
