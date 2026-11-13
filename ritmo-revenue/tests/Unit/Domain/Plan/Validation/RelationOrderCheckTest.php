<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan\Validation;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Plan\Validation\RelationOrderCheck;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Coherence\RelationWarning;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * Finding 12 of the M2 review: after the validator refuses an item, the related rooms left in the
 * plan must still keep their order (at_least, between). Deluxe must stay at least 10% above
 * Standard; Suite must stay at most at the dearer of Standard and Deluxe.
 */
final class RelationOrderCheckTest extends TestCase
{
    private const NIGHT = '2027-03-10';

    public function testAReferenceRaisedWithoutItsTargetIsRefused(): void
    {
        $result = self::validate([self::item('Standard', 950.0, 800.0)], ['Deluxe' => 1000.0]);

        self::assertSame([RelationOrderCheck::RELATION_BROKEN], $result->errorCodes());
        self::assertSame([0], $result->rejectedItems(), 'the reference: the target has no item');
    }

    public function testATargetLoweredWithoutItsReferenceIsRefused(): void
    {
        $result = self::validate([self::item('Deluxe', 900.0, 1000.0)], ['Standard' => 850.0]);

        self::assertSame([RelationOrderCheck::RELATION_BROKEN], $result->errorCodes());
    }

    public function testRoomsMovedTogetherKeepTheOrder(): void
    {
        $result = self::validate([self::item('Standard', 950.0, 800.0), self::item('Deluxe', 1100.0, 1000.0)], []);

        self::assertSame([], $result->errorCodes());
    }

    public function testAPlanThatNarrowsAnOrderAlreadyBrokenIsAccepted(): void
    {
        $result = self::validate([self::item('Deluxe', 920.0, 900.0)], ['Standard' => 850.0]);

        self::assertSame([], $result->errorCodes(), 'broken by 35 before, by 15 after');
    }

    public function testARelationTheCoherenceStageDecidedKnowinglyIsExempt(): void
    {
        $report = new RelationReport();
        $report->warn(new RelationWarning(RelationWarning::FLOOR_OVER_BAND_CEILING, 'deluxe-over-standard', 'Deluxe', Days::date(self::NIGHT)));

        $result = self::validate([self::item('Deluxe', 900.0, 1000.0)], ['Standard' => 850.0], $report);

        self::assertSame([], $result->errorCodes());
    }

    public function testASoldOutReferenceDoesNotPullTheTarget(): void
    {
        $result = self::validate([self::item('Deluxe', 900.0, 1000.0)], ['Standard' => 850.0], null, ['Standard' => 6]);

        self::assertSame([], $result->errorCodes());
    }

    public function testABetweenTargetAboveTheDearerReferenceIsRefused(): void
    {
        $result = self::validate([self::item('Suite', 1250.0, 1100.0)], ['Standard' => 850.0, 'Deluxe' => 1200.0]);

        self::assertSame([RelationOrderCheck::RELATION_BROKEN], $result->errorCodes());
        self::assertSame([], self::validate([self::item('Suite', 1150.0, 1100.0)], ['Standard' => 850.0, 'Deluxe' => 1200.0])->errorCodes());
    }

    public function testThePlanValidatesOnceTheRefusedItemIsDropped(): void
    {
        $items = [self::item('Standard', 950.0, 800.0), self::item('Suite', 990.0, 1100.0)];
        $first = self::validate($items, ['Deluxe' => 1000.0]);
        $second = self::validate(array_values(array_diff_key($items, array_flip($first->rejectedItems()))), ['Deluxe' => 1000.0]);

        self::assertSame([0], $first->rejectedItems());
        self::assertTrue($second->isValid());
    }

    public function testWithoutTheRelationsTheCheckIsOff(): void
    {
        $result = self::validate([self::item('Standard', 950.0, 800.0)], ['Deluxe' => 1000.0], null, [], false);

        self::assertSame([], $result->errorCodes());
    }

    private static function suite(): RoomType
    {
        return new RoomType('inn', 'Suite', 'Suite', 2, 'pms-3', 'pricing-3', 1200.0, 700.0, 3000.0, 800.0);
    }

    private static function item(string $room, float $floor, float $current): PlanItem
    {
        return new PlanItem($room, Days::date(self::NIGHT), minPrice: $floor, reasonCode: 'pace_step', before: new NightState($current, null, null, 1));
    }

    /**
     * @param list<PlanItem>        $items
     * @param array<string, float>  $prices room => current price of the rooms without an item
     * @param array<string, int>    $sold   room => units sold
     */
    private static function validate(array $items, array $prices, ?RelationReport $report = null, array $sold = [], bool $withRelations = true): ValidationResult
    {
        $rooms = [EngineSample::deluxe(), EngineSample::standard(), self::suite()];
        $nights = [];
        foreach ($rooms as $room) {
            $price = $prices[$room->code] ?? null;
            $nights[PlanItem::keyOf($room->code, Days::date(self::NIGHT))] = new CalendarNight($price, null, $sold[$room->code] ?? 0, $room->units);
        }
        $relations = [
            Relation::fromArray(['id' => 'deluxe-over-standard', 'property' => 'inn', 'type' => 'at_least', 'target' => 'Deluxe', 'params' => ['reference' => 'Standard', 'margin' => ['type' => 'percent', 'value' => 10]]]),
            Relation::fromArray(['id' => 'suite-between', 'property' => 'inn', 'type' => 'between', 'target' => 'Suite', 'params' => ['low_reference' => 'Standard', 'high_reference' => 'Deluxe', 'references' => 'all']]),
        ];
        $context = new ValidationContext(new FreshCalendar($nights), relationReport: $report, relations: $withRelations ? new RelationGraph($relations, $rooms) : null);
        $clock = PlanSample::clock();
        $plan = new Plan('inn', Days::date(PlanSample::TODAY), $clock->now(), $items);

        return (new Validator(EngineSample::rules(), $clock))->validate($plan, EngineSample::property(), $rooms, $context);
    }
}
