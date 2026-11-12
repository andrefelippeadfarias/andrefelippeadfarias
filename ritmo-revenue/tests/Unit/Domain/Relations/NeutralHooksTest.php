<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\Neutral\NeutralHooks;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The hooks of a site without owner orders, ladder or switch market data, on the engine sample.
 */
final class NeutralHooksTest extends TestCase
{
    private static function config(): RelationsConfig
    {
        return RelationsConfig::fromArray(['price_bands' => ['items' => [[
            'id' => 'deluxe-band', 'property' => 'inn', 'room' => 'Deluxe', 'night_types' => ['weekend'],
            'price_plan' => 'public', 'min_amount' => 800, 'max_amount' => 1000, 'max_days_out' => 30,
        ]]]]);
    }

    public function testFloorsAreTheHardFloorAndTheSwitchFloorAddsTheHolidayFloor(): void
    {
        $rules = EngineSample::rules();
        $deluxe = EngineSample::deluxe();
        $hooks = NeutralHooks::for($rules, self::config(), [$deluxe, EngineSample::standard()]);
        $weekday = Days::date('2027-03-10');
        $holiday = Days::date('2027-03-18');
        $hard = (new Floors($rules))->hardFloor($deluxe, $weekday)->amount;

        self::assertSame($hard, $hooks->floors->inForce('Deluxe', $weekday)->value);
        self::assertFalse($hooks->floors->inForce('Deluxe', $weekday)->fromOrder);
        self::assertSame($hard, $hooks->floors->lowestAllowed('Deluxe', $weekday));
        self::assertSame($hard, $hooks->floors->ruleFloor('Deluxe', $weekday, null));
        self::assertNull($hooks->floors->safetyException('Deluxe', $weekday));
        self::assertSame(525.0, $hooks->floors->switchFloor('Deluxe', $weekday), 'the safety limit');
        self::assertSame(1200.0, $hooks->floors->switchFloor('Deluxe', $holiday), 'holiday floor 600 discounted / 0.5');
        $relation = RelationsSample::relation('x', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH']);
        self::assertSame($hard, $hooks->floors->betweenHints($relation, 'Deluxe', $weekday)->ownFloor);
    }

    public function testNothingIsOrderedFrozenOrPricedByALadder(): void
    {
        $hooks = NeutralHooks::for(EngineSample::rules(), self::config(), [EngineSample::deluxe()]);
        $night = Days::date('2027-03-10');

        self::assertFalse($hooks->orders->isFrozen('Deluxe', $night));
        self::assertFalse($hooks->orders->hasOwnerPrice('Deluxe', $night));
        self::assertNull($hooks->orders->floorOrderRef('Deluxe', $night));
        self::assertFalse($hooks->orders->isActive('any', 'Deluxe', $night));
        self::assertNull($hooks->orders->releases('any', 'Deluxe', $night));
        self::assertFalse($hooks->strategy->governs('Deluxe', $night));
        self::assertFalse($hooks->strategy->pinsFloorToCeiling('Deluxe', $night));
        self::assertNull($hooks->market->sentPrice(RelationsSample::lodgeRelations()[0], 'Deluxe', $night));
    }

    public function testBandsAreConvertedToTheSentPlanOnTheirNightTypesUpToTheirHorizon(): void
    {
        $hooks = NeutralHooks::for(EngineSample::rules(), self::config(), [EngineSample::deluxe()]);
        $band = $hooks->bands->sentBand('Deluxe', Days::date('2027-03-12'));

        self::assertSame([1000.0, 1250.0], [$band?->low, $band?->high], 'public 800 to 1000 / 0.8');
        self::assertNull($hooks->bands->sentBand('Deluxe', Days::date('2027-03-10')), 'weekday');
        self::assertNull($hooks->bands->sentBand('Standard', Days::date('2027-03-12')), 'no band');
        self::assertSame('2027-03-31', Days::key($hooks->bands->lastNight('Deluxe', Days::date('2027-03-01')) ?? Days::date('1970-01-01')));
        self::assertNull($hooks->bands->lastNight('Standard', Days::date('2027-03-01')));
        self::assertSame('weekend', $hooks->calendar->nightType(Days::date('2027-03-12'))->value);
    }

    public function testTheDefaultRuleSetGivesAWorkingCalendar(): void
    {
        $rules = RuleSet::fromArray(Schema::ruleSet()->defaults());
        $room = new RoomType('inn', 'Any', 'Any room', 1, '', '', 100.0, 50.0, 500.0, 60.0);

        self::assertGreaterThan(0.0, NeutralHooks::for($rules, RelationsConfig::neutral(), [$room])->floors->switchFloor('Any', Days::date('2027-03-10')));
    }
}
