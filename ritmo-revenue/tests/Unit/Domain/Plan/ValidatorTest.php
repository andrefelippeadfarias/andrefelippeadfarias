<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The validator on the sample inn (see EngineSample). "Now" is Wednesday 3 March 2027, noon UTC.
 *
 * Deluxe: base 1000 (so 900 is exactly -10%), listing 600 to 3000, safety limit 525; hard floor
 * 800 on weekdays, 1166.67 on weekends and 1800 on the nights of the fair.
 * Standard: base 500, listing 300 to 1500, safety limit 240; its hard floor is the listing minimum.
 */
final class ValidatorTest extends TestCase
{
    private const NOW = '2027-03-03T12:00:00+00:00';
    private const TODAY = '2027-03-03';
    private const WEEKDAY = '2027-03-10';

    /**
     * A valid item unless changed: the Deluxe room next Wednesday, floor 900, price 1000 before.
     *
     * @param array<string, mixed> $with
     */
    private static function item(array $with = []): PlanItem
    {
        return new PlanItem(
            $with['room'] ?? 'Deluxe',
            Days::date($with['night'] ?? self::WEEKDAY),
            array_key_exists('min', $with) ? $with['min'] : 900.0,
            $with['max'] ?? null,
            $with['minStay'] ?? null,
            $with['reason'] ?? 'pace_step',
            [],
            array_key_exists('before', $with) ? $with['before'] : new NightState(1000.0, 600.0, 3000.0, 1),
        );
    }

    /**
     * @param list<PlanItem> $items
     */
    private static function validate(array $items, ?RuleSet $rules = null, string $planToday = self::TODAY): ValidationResult
    {
        $clock = new FixedClock(self::NOW);
        $plan = new Plan('inn', Days::date($planToday), $clock->now(), $items);

        return (new Validator($rules ?? EngineSample::rules(), $clock))->validate($plan, EngineSample::property(), [EngineSample::deluxe(), EngineSample::standard()]);
    }

    /**
     * @param array<string, mixed> $with
     *
     * @return list<string>
     */
    private static function errorsOf(array $with, ?RuleSet $rules = null): array
    {
        return self::validate([self::item($with)], $rules)->errorCodes();
    }

    public function testAValidPlanBecomesAValidatedPlan(): void
    {
        $rules = EngineSample::rules();
        $result = self::validate([self::item(), self::item(['room' => 'Standard', 'min' => 400.0, 'before' => new NightState(450.0, 300.0, 1500.0)])], $rules);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors);
        self::assertSame([], $result->warnings);
        self::assertSame([], $result->rejectedItems());
        $validated = $result->plan;
        self::assertInstanceOf(ValidatedPlan::class, $validated);
        self::assertCount(2, $validated->plan);
        self::assertSame(self::NOW, $validated->validatedAt->format('c'));
        self::assertSame(self::TODAY, Days::key($validated->today));
        self::assertSame($rules->hash(), $validated->rulesHash);
        self::assertSame([], $validated->warnings);
    }

    public function testAnEmptyPlanIsValid(): void
    {
        self::assertTrue(self::validate([])->isValid());
    }

    public function testAValidatedPlanIsOnlyCurrentOnItsDay(): void
    {
        $validated = self::validate([self::item()])->plan;

        self::assertSame('UTC', $validated->timezone);
        self::assertTrue($validated->isCurrent(new FixedClock('2027-03-03T23:59:59+00:00')));
        self::assertFalse($validated->isCurrent(new FixedClock('2027-03-04T00:00:00+00:00')));
    }

    public function testTodayIsTheDayOfThePropertyNotOfTheServer(): void
    {
        // 01:00 UTC on the 4th is still 22:00 on the 3rd in Sao Paulo.
        $clock = new FixedClock('2027-03-04T01:00:00+00:00');
        $property = new Property('inn', 'Sample Inn', 'America/Sao_Paulo', 'BRL');
        $validator = new Validator(EngineSample::rules(), $clock);
        $validate = static fn (string $planToday, PlanItem $item): ValidationResult => $validator->validate(
            new Plan('inn', Days::date($planToday), $clock->now(), [$item]),
            $property,
            [EngineSample::deluxe()]
        );

        self::assertSame([PlanIssue::STALE_PLAN], $validate('2027-03-04', self::item())->errorCodes(), 'a plan dated by the server day');
        self::assertSame([PlanIssue::RAISE_TODAY], $validate('2027-03-03', self::item(['night' => '2027-03-03', 'min' => 1010.0]))->errorCodes(), 'the 3rd is still tonight there');
        self::assertSame([PlanIssue::PAST_DATE], $validate('2027-03-03', self::item(['night' => '2027-03-02']))->errorCodes());

        $validated = $validate('2027-03-03', self::item())->plan;
        self::assertSame('America/Sao_Paulo', $validated?->timezone);
        self::assertTrue($validated->isCurrent(new FixedClock('2027-03-04T02:59:59+00:00')));
        self::assertFalse($validated->isCurrent(new FixedClock('2027-03-04T03:00:00+00:00')), 'midnight in Sao Paulo');
    }

    public function testOnlyTheValidatorCanCreateAValidatedPlan(): void
    {
        $class = new ReflectionClass(ValidatedPlan::class);

        self::assertTrue($class->getConstructor()->isPrivate());
        self::assertTrue($class->isFinal());
        self::assertSame([], $class->getMethods(ReflectionMethod::IS_STATIC), 'no factory');
        self::assertNull(self::validate([self::item(['min' => 100.0])])->plan, 'a plan with an error is never sealed');
    }

    public function testAPlanOfAnotherDayMustBeRedone(): void
    {
        $result = self::validate([self::item()], null, '2027-03-02');

        self::assertFalse($result->isValid());
        self::assertSame([PlanIssue::STALE_PLAN], $result->errorCodes());
        self::assertNull($result->errors[0]->itemIndex);
        self::assertSame(['plan_today' => '2027-03-02', 'today' => self::TODAY], $result->errors[0]->params);
        self::assertSame([], $result->rejectedItems(), 'the whole plan is stale, not one item');
    }

    public function testAPlanOfAnotherPropertyIsRefused(): void
    {
        $clock = new FixedClock(self::NOW);
        $plan = new Plan('other', Days::date(self::TODAY), $clock->now(), []);

        $this->expectException(InvalidArgumentException::class);
        (new Validator(EngineSample::rules(), $clock))->validate($plan, EngineSample::property(), []);
    }

    public function testRoomsMustBeRoomTypesOfTheProperty(): void
    {
        self::assertSame([PlanIssue::UNKNOWN_ROOM], self::errorsOf(['room' => 'Suite']));

        $clock = new FixedClock(self::NOW);
        $foreign = new RoomType('other', 'Suite', 'Suite', 1, '', '', 1000.0, 600.0, 3000.0, 700.0);
        $plan = new Plan('inn', Days::date(self::TODAY), $clock->now(), [self::item(['room' => 'Suite'])]);
        $result = (new Validator(EngineSample::rules(), $clock))->validate($plan, EngineSample::property(), [$foreign]);
        self::assertSame([PlanIssue::UNKNOWN_ROOM], $result->errorCodes(), 'a room of another property does not count');
    }

    public function testTwoItemsForTheSameRoomNightAreRejected(): void
    {
        $result = self::validate([self::item(), self::item(['night' => '2027-03-11']), self::item(['min' => 950.0])]);

        self::assertSame([PlanIssue::DUPLICATE_ITEM], $result->errorCodes());
        self::assertSame(2, $result->errors[0]->itemIndex);
        self::assertSame(['first_item' => 0], $result->errors[0]->params);
        self::assertSame('Deluxe', $result->errors[0]->roomCode);
        self::assertSame(self::WEEKDAY, Days::key($result->errors[0]->date));
        self::assertTrue($result->errors[0]->isError());
    }

    public function testAnItemNeedsAReason(): void
    {
        self::assertSame([PlanIssue::MISSING_REASON], self::errorsOf(['reason' => '']));
    }

    public function testAPastNightIsRejectedAndNotLookedAtFurther(): void
    {
        self::assertSame([PlanIssue::PAST_DATE], self::errorsOf(['night' => '2027-03-02', 'min' => 100.0]));
    }

    public function testTheFloorOfTonightMayNotRaiseThePrice(): void
    {
        $tonight = static fn (float $min, ?float $price): array => ['night' => self::TODAY, 'min' => $min, 'before' => new NightState($price, 600.0, 3000.0)];

        self::assertSame([PlanIssue::RAISE_TODAY], self::errorsOf($tonight(1010.0, 1000.0)));
        self::assertSame([], self::errorsOf($tonight(1000.0, 1000.0)), 'a floor at the price does not raise it');
        self::assertSame([], self::errorsOf($tonight(900.0, 1000.0)));
        self::assertSame([PlanIssue::TODAY_WITHOUT_CURRENT_PRICE], self::errorsOf($tonight(900.0, null)));
        self::assertSame([PlanIssue::TODAY_WITHOUT_CURRENT_PRICE], self::errorsOf(['night' => self::TODAY, 'before' => null]));
        self::assertSame([], self::errorsOf(['night' => '2027-03-04', 'min' => 1010.0]), 'tomorrow a floor may raise the price');
        self::assertSame([], self::errorsOf(['night' => self::TODAY, 'min' => null, 'max' => 900.0]), 'a ceiling alone can only lower tonight');
    }

    public function testWarnsWhenTheRoundingAloneLiftsTonightAboveItsPrice(): void
    {
        // 995.50 asked is under the price of 999, but it is sent as +0% of the base: 1000.
        $result = self::validate([self::item(['night' => self::TODAY, 'min' => 995.5, 'before' => new NightState(999.0, 600.0, 3000.0)])]);

        self::assertTrue($result->isValid());
        self::assertSame([PlanIssue::TODAY_FLOOR_ROUNDS_ABOVE_PRICE], $result->warningCodes());
        self::assertSame(['applied_floor' => 1000.0, 'current_price' => 999.0], $result->warnings[0]->params);
        self::assertFalse($result->warnings[0]->isError());
        self::assertSame($result->warnings, $result->plan->warnings);
    }

    public function testAFloorUnderTheSafetyLimitIsRejected(): void
    {
        $result = self::validate([self::item(['min' => 500.0])]);

        self::assertSame([PlanIssue::BELOW_SAFETY_LIMIT], $result->errorCodes(), 'reported once, as the most serious floor');
        self::assertSame(['amount' => 500.0, 'safety_limit' => 525.0], $result->errors[0]->params);
        self::assertStringContainsString('safety limit of 525.00', $result->errors[0]->message);
    }

    public function testAFloorUnderTheHardFloorOfTheNightIsRejected(): void
    {
        $result = self::validate([self::item(['min' => 700.0])]);
        self::assertSame([PlanIssue::BELOW_HARD_FLOOR], $result->errorCodes());
        self::assertSame(['amount' => 700.0, 'hard_floor' => 800.0, 'floor_label' => Floors::OWNER], $result->errors[0]->params);

        self::assertSame([], self::errorsOf(['min' => 800.0]));
        self::assertSame([PlanIssue::BELOW_HARD_FLOOR], self::errorsOf(['night' => '2027-03-12', 'min' => 1100.0]), 'the weekend floor is 1166.67');
        self::assertSame([], self::errorsOf(['night' => '2027-03-12', 'min' => 1166.0]), 'sent as +17%: 1170');
        self::assertSame([PlanIssue::BELOW_HARD_FLOOR], self::errorsOf(['night' => '2027-03-18', 'min' => 1700.0, 'before' => new NightState(2000.0, 600.0)]), 'the floor of the fair is 1800');
    }

    public function testAFloorHeldAtTheHardFloorMayStandOneUnitUnderIt(): void
    {
        $ownerFloor = static fn (float $amount): RuleSet => EngineSample::rules(static function (array $data) use ($amount): array {
            $data['floors']['owner'][0]['amount'] = $amount;

            return $data;
        });

        self::assertSame([], self::errorsOf(['min' => 800.0], $ownerFloor(400.3)), 'hard floor 800.60: a step rounded down to 800 passes');
        self::assertSame([PlanIssue::BELOW_HARD_FLOOR], self::errorsOf(['min' => 800.0], $ownerFloor(401.0)), 'hard floor 802');
    }

    public function testAFloorUnderTheListingMinimumIsNamedAsSuch(): void
    {
        $result = self::validate([self::item(['room' => 'Standard', 'min' => 280.0, 'before' => new NightState(350.0, 300.0)])]);

        self::assertSame([PlanIssue::BELOW_LISTING_MIN], $result->errorCodes());
        self::assertSame(Floors::LISTING_MIN, $result->errors[0]->params['floor_label']);
    }

    public function testFloorAndCeilingMayNotPassTheListingMaximum(): void
    {
        self::assertSame([PlanIssue::ABOVE_LISTING_MAX], self::errorsOf(['min' => 3010.0, 'before' => new NightState(3000.0, 600.0)]));
        self::assertSame([], self::errorsOf(['min' => 3000.0, 'before' => new NightState(3000.0, 600.0)]));
        self::assertSame([PlanIssue::ABOVE_LISTING_MAX], self::errorsOf(['max' => 3001.0]));
        self::assertSame([], self::errorsOf(['max' => 3000.4]), 'within the slack');
        self::assertSame([PlanIssue::ABOVE_LISTING_MAX, PlanIssue::ABOVE_LISTING_MAX], self::errorsOf(['min' => 3010.0, 'max' => 3020.0, 'before' => new NightState(3000.0, 600.0)]), 'one for the floor, one for the ceiling');
    }

    public function testACeilingUnderTheFloorIsRejected(): void
    {
        $result = self::validate([self::item(['min' => 900.0, 'max' => 850.0])]);
        self::assertSame([PlanIssue::CEILING_BELOW_FLOOR], $result->errorCodes());
        self::assertSame(['ceiling' => 850.0, 'floor' => 900.0, 'floor_in_force' => false], $result->errors[0]->params);

        self::assertSame([], self::errorsOf(['min' => 900.0, 'max' => 900.0]), 'a locked price');
    }

    public function testACeilingAloneRespectsTheFloorInForceAndTheFloorsOfTheNight(): void
    {
        $result = self::validate([self::item(['min' => null, 'max' => 900.0, 'before' => new NightState(1100.0, 1000.0)])]);
        self::assertSame([PlanIssue::CEILING_BELOW_FLOOR], $result->errorCodes(), 'the pricing system would stay at the floor in force');
        self::assertTrue($result->errors[0]->params['floor_in_force']);

        self::assertSame([], self::errorsOf(['min' => null, 'max' => 900.0, 'before' => new NightState(1100.0, 900.5)]), 'within one unit');
        self::assertSame([], self::errorsOf(['min' => null, 'max' => 900.0, 'before' => new NightState(1100.0)]), 'no floor known');
        self::assertSame([PlanIssue::BELOW_HARD_FLOOR], self::errorsOf(['min' => null, 'max' => 700.0, 'before' => new NightState(1100.0)]), 'a ceiling is the highest price of the night');
        self::assertSame([PlanIssue::BELOW_SAFETY_LIMIT], self::errorsOf(['min' => null, 'max' => 500.0, 'before' => new NightState(1100.0)]));
    }

    public function testAnItemThatChangesNothingIsRejected(): void
    {
        $standard = static fn (array $with): array => ['room' => 'Standard'] + $with;

        self::assertSame([PlanIssue::NO_EFFECT], self::errorsOf($standard(['min' => 400.0, 'before' => new NightState(450.0, 400.5)])), 'the floor in force, within one unit');
        self::assertSame([], self::errorsOf($standard(['min' => 400.0, 'before' => new NightState(450.0, 402.0)])));
        self::assertSame([], self::errorsOf($standard(['min' => 400.0, 'before' => new NightState(450.0)])), 'the floor in force is not known');
        self::assertSame([PlanIssue::NO_EFFECT], self::errorsOf($standard(['min' => 400.0, 'max' => 400.0, 'before' => new NightState(400.0, 400.0, 400.0)])), 'already locked at that price');
        self::assertSame([], self::errorsOf($standard(['min' => 400.0, 'max' => 400.0, 'before' => new NightState(400.0, 400.0, 1500.0)])), 'the ceiling changes');
        self::assertSame([PlanIssue::NO_EFFECT], self::errorsOf($standard(['min' => null, 'minStay' => 2, 'before' => new NightState(450.0, 300.0, 1500.0, 2)])));
        self::assertSame([], self::errorsOf($standard(['min' => null, 'minStay' => 2, 'before' => new NightState(450.0, 300.0, 1500.0, 1)])));
        self::assertSame([], self::errorsOf($standard(['min' => 400.0, 'minStay' => 2, 'before' => new NightState(450.0, 400.0, 1500.0, 1)])), 'one value that changes is enough');
    }

    public function testWithoutWhatIsInForceTheEffectCannotBeCheckedAndAWarningSaysSo(): void
    {
        $result = self::validate([self::item(['before' => null])]);

        self::assertTrue($result->isValid());
        self::assertSame([PlanIssue::NO_BEFORE], $result->warningCodes());
    }

    public function testWarnsAboutARiseLargerThanOneStepOfTheEngine(): void
    {
        // 7 days away the largest step is 15%: from 1000, 1150, plus the slack of the roundings (1% of the base + 1).
        self::assertSame([], self::validate([self::item(['min' => 1160.0])])->warningCodes());

        $result = self::validate([self::item(['min' => 1170.0])]);
        self::assertTrue($result->isValid(), 'a floor may require it: a warning, not an error');
        self::assertSame([PlanIssue::RISE_ABOVE_MAX_STEP], $result->warningCodes());
        self::assertSame(['applied_floor' => 1170.0, 'current_price' => 1000.0, 'max_step_percent' => 15.0], $result->warnings[0]->params);

        self::assertSame([], self::validate([self::item(['night' => '2027-04-12', 'min' => 1060.0])])->warningCodes(), '40 days away the step is 5%: 1050 plus the slack');
        self::assertSame([PlanIssue::RISE_ABOVE_MAX_STEP], self::validate([self::item(['night' => '2027-04-12', 'min' => 1070.0])])->warningCodes());
    }

    public function testEveryErrorOfEveryItemIsReported(): void
    {
        $result = self::validate([
            self::item(['min' => 500.0, 'max' => 450.0, 'reason' => '']),
            self::item(['night' => '2027-03-11']),
            self::item(['night' => '2027-03-01']),
        ]);

        self::assertSame([PlanIssue::MISSING_REASON, PlanIssue::BELOW_SAFETY_LIMIT, PlanIssue::CEILING_BELOW_FLOOR, PlanIssue::PAST_DATE], $result->errorCodes());
        self::assertSame([0, 2], $result->rejectedItems());
        self::assertNull($result->plan);
    }

    public function testWithoutTheRejectedItemsThePlanPasses(): void
    {
        $clock = new FixedClock(self::NOW);
        $validator = new Validator(EngineSample::rules(), $clock);
        $rooms = [EngineSample::deluxe()];
        $plan = new Plan('inn', Days::date(self::TODAY), $clock->now(), [self::item(['min' => 500.0]), self::item(['night' => '2027-03-11'])]);

        $first = $validator->validate($plan, EngineSample::property(), $rooms);
        $second = $validator->validate($plan->without($first->rejectedItems()), EngineSample::property(), $rooms);

        self::assertFalse($first->isValid());
        self::assertTrue($second->isValid(), 'the validator keeps nothing from one plan to the next');
        self::assertCount(1, $second->plan->plan);
    }

    public function testTheStepsOfTheEnginePassTheValidator(): void
    {
        $clock = new FixedClock(self::NOW);
        $rules = EngineSample::rules();
        $engine = new PaceEngine($rules, $clock);
        $decisions = [];
        foreach ([EngineSample::deluxe(), EngineSample::standard()] as $room) {
            for ($offset = 0; $offset <= 45; $offset++) {
                $night = Days::add(Days::date(self::TODAY), $offset);
                foreach ([[0, 700.0], [1, 1300.0], [$room->units - 1, 900.0]] as [$sold, $price]) {
                    $decisions[] = $engine->decide(new NightContext(EngineSample::property(), $room, $night, $price * $room->base / 1000, $sold, $room->units, EngineSample::anchor(Days::key($night)), $room->min, $room->max));
                }
            }
        }
        $steps = array_values(array_filter($decisions, static fn ($decision): bool => $decision->isStep()));
        self::assertGreaterThan(100, count($steps));
        self::assertNotSame([], array_filter($steps, static fn ($decision): bool => $decision->heldAtFloor), 'some steps stop at the hard floor');

        // One item per room night: validate each scenario on its own plan.
        $conflicts = 0;
        foreach ($steps as $step) {
            $room = $step->roomCode === 'Deluxe' ? EngineSample::deluxe() : EngineSample::standard();
            $plan = Plan::fromDecisions(EngineSample::property(), $clock, [$step], [
                $step->roomCode . '|' . Days::key($step->night) => new NightState($step->currentPrice, $room->min, $room->max),
            ]);
            $result = (new Validator($rules, $clock))->validate($plan, EngineSample::property(), [EngineSample::deluxe(), EngineSample::standard()]);

            if ($step->floor->amount > $room->max) {
                // The fair's floor for the Standard room (2250) is over its listing maximum (1500): the engine can only
                // go to the maximum, and the validator reports the conflict for a person to solve.
                $conflicts++;
                self::assertSame([PlanIssue::BELOW_HARD_FLOOR], $result->errorCodes(), json_encode($step->toArray()));
                continue;
            }
            self::assertSame([], $result->errorCodes(), json_encode($step->toArray()));
        }
        self::assertGreaterThan(0, $conflicts);
    }
}
