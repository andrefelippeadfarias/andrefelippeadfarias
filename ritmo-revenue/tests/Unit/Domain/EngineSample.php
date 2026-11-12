<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * A small made-up inn for the unit tests of the engine: one property, two room types and a rule
 * set whose numbers are easy to follow by hand.
 *
 * Calendar: Friday and Saturday are weekend nights. "Spring fair" (18 to 21 March 2027) is priced
 * as holiday with its own floor of 900; "Local week" (5 to 7 April 2027) is priced as weekend.
 * Pace (schema defaults): band 15, very behind 25, k 0.5, steps of 5% (30+ days), 10% (8+) and
 * 15% (0+), tolerance 2%, weak anchor under 5 samples or over 14 days old.
 * Factors: public 0.8; discounted 0.5 on weekdays and holidays, 0.6 on weekends, and 0.4 for the
 * Standard room on holidays.
 * Floors: 75% of the reference minimum; owner floors of 400 (weekday) and 700 (weekend),
 * discounted plan, rooms with a tub; quality floor 800 sent on weekend and holiday nights, rooms
 * with a tub; holiday floor 600 in the discounted plan.
 */
final class EngineSample
{
    public const TIMEZONE = 'UTC';

    /** A Wednesday. */
    public const TODAY = '2027-03-03';

    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $change edits the rule array before it is validated
     */
    public static function rules(?callable $change = null): RuleSet
    {
        $data = Schema::ruleSet()->defaults();
        $data['calendar']['holidays'] = [
            ['name' => 'Spring fair', 'start' => '2027-03-18', 'end' => '2027-03-21', 'night_type' => 'holiday', 'min_stay' => 2, 'floor' => 900],
            ['name' => 'Local week', 'start' => '2027-04-05', 'end' => '2027-04-07', 'night_type' => 'weekend', 'min_stay' => null, 'floor' => null],
        ];
        $data['floors']['owner'] = [
            ['night_type' => 'weekday', 'amount' => 400, 'price_plan' => 'discounted', 'room_tags' => ['tub']],
            ['night_type' => 'weekend', 'amount' => 700, 'price_plan' => 'discounted', 'room_tags' => ['tub']],
        ];
        $data['floors']['quality_floor'] = ['amount' => 800, 'price_plan' => 'sent', 'night_types' => ['weekend', 'holiday'], 'room_tags' => ['tub']];
        $data['floors']['holiday_floor'] = ['amount' => 600, 'price_plan' => 'discounted'];
        $data['price_factors']['properties']['*'] = [
            'public' => ['weekday' => 0.8, 'weekend' => 0.8, 'holiday' => 0.8],
            'discounted' => ['weekday' => 0.5, 'weekend' => 0.6, 'holiday' => 0.5],
        ];
        $data['price_factors']['room_overrides'] = [
            ['room' => 'Standard', 'price_plan' => 'discounted', 'night_type' => 'holiday', 'factor' => 0.4],
        ];

        return RuleSet::fromArray($change === null ? $data : $change($data));
    }

    public static function property(string $tier = 'standard'): Property
    {
        return new Property('inn', 'Sample Inn', self::TIMEZONE, 'EUR', tier: $tier);
    }

    /**
     * Four units with a tub: base 1000, listing 600 to 3000, reference minimum 700.
     */
    public static function deluxe(): RoomType
    {
        return new RoomType('inn', 'Deluxe', 'Deluxe room', 4, 'pms-1', 'pricing-1', 1000.0, 600.0, 3000.0, 700.0, ['tub']);
    }

    /**
     * Six units without a tub: base 500, listing 300 to 1500, reference minimum 320.
     */
    public static function standard(): RoomType
    {
        return new RoomType('inn', 'Standard', 'Standard room', 6, 'pms-2', 'pricing-2', 500.0, 300.0, 1500.0, 320.0);
    }

    /**
     * A measured anchor with public prices 400, 600, 800, 1000 and 1200, taken on $capturedAt.
     */
    public static function anchor(string $night, int $n = 12, ?string $capturedAt = '2027-03-01', AnchorOrigin $origin = AnchorOrigin::Measured): MarketAnchor
    {
        return new MarketAnchor(Days::date($night), 400.0, 600.0, 800.0, 1000.0, 1200.0, $n, $capturedAt === null ? null : Days::date($capturedAt), $origin);
    }
}
