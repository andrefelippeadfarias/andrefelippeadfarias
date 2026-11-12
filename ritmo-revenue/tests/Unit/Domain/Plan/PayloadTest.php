<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Payload\CallKind;
use Ritmo\Domain\Plan\Payload\Payload;
use Ritmo\Domain\Plan\Payload\PayloadBuilder;
use Ritmo\Domain\Plan\Payload\RoomCall;
use Ritmo\Domain\Plan\Payload\Slice;
use Ritmo\Domain\Plan\Payload\Slicer;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The payload of a validated plan and its slices, on the sample inn (Deluxe base 1000, listing
 * pricing-1; Standard base 500, pricing-2). Tonight is 3 March 2027.
 */
final class PayloadTest extends TestCase
{
    private static function validated(array $items, array $deletions = [], array $listing = []): ValidatedPlan
    {
        $orders = PlanSample::orders([['id' => 'listing', 'type' => 'rule', 'releases' => ['listing']]]);
        $result = PlanSample::validate($items, PlanSample::context(orders: $orders), null, $deletions, $listing);
        self::assertTrue($result->isValid(), implode(', ', $result->errorCodes()));

        return $result->plan;
    }

    private static function build(ValidatedPlan $plan, ?PlanConfig $config = null): Payload
    {
        return (new PayloadBuilder('sample-pms', $config ?? new PlanConfig()))->build($plan, EngineSample::property(), [EngineSample::deluxe(), EngineSample::standard()]);
    }

    public function testFloorsAndCeilingsGoAsWholePercentagesOverTheBase(): void
    {
        $payload = self::build(self::validated([
            PlanSample::item(['minPrice' => 903.0, 'maxPrice' => 1207.0, 'reasonText' => 'pace step']),
            PlanSample::item(['roomCode' => 'Standard', 'date' => '2027-03-11', 'minPrice' => 381.0, 'maxPrice' => 384.0, 'before' => new NightState(400.0)]),
        ]));

        self::assertSame([
            'deletions' => [],
            'writes' => [
                ['listing_id' => EngineSample::deluxe()->pricingRef, 'pms' => 'sample-pms', 'overrides' => [[
                    'date' => '2027-03-10', 'reason' => 'pace step',
                    'min_price' => '-9', 'min_price_type' => 'percent_base', 'currency' => 'EUR',
                    'max_price' => '20', 'max_price_type' => 'percent_base',
                ]]],
                ['listing_id' => EngineSample::standard()->pricingRef, 'pms' => 'sample-pms', 'overrides' => [[
                    'date' => '2027-03-11', 'reason' => 'pace_step',
                    'min_price' => '-23', 'min_price_type' => 'percent_base', 'currency' => 'EUR',
                    'max_price' => '-23', 'max_price_type' => 'percent_base',
                ]]],
            ],
            'listing' => [],
        ], $payload->toArray(), 'floor 903 rounds up to -9% (910), ceiling 1207 down to +20%; a ceiling never under the floor');
    }

    public function testStayDaysAndTheReasonOfAnItem(): void
    {
        $long = str_repeat('é', 300);
        $payload = self::build(self::validated([
            PlanSample::item(['minPrice' => null, 'minStay' => 2, 'checkOutMask' => '1111101', 'keepReason' => true, 'orderId' => null, 'before' => new NightState(1000.0), 'reasonText' => 'kept']),
            PlanSample::item(['date' => '2027-03-11', 'reasonText' => $long]),
        ]), new PlanConfig(reasonMaxLength: 255));
        [$days, $long] = $payload->writes[0]->toArray()['overrides'];

        self::assertSame(['date' => '2027-03-10', 'min_stay' => '2', 'check_in_check_out_enabled' => '1', 'check_in' => '1111111', 'check_out' => '1111101'], $days, 'no reason: the date rule keeps its own; the other mask all open');
        self::assertSame(255, mb_strlen($long['reason']), 'cut by characters, not bytes');
    }

    public function testDeletionsAndListingChangesGoPerListingInPlanOrder(): void
    {
        $payload = self::build(self::validated(
            [PlanSample::item()],
            [new PlanDeletion('Standard', Days::date('2027-03-09'), 'CEILING_RELEASE_DELETE'), new PlanDeletion('Deluxe', Days::date('2027-03-10'), 'CEILING_RELEASE_DELETE'), new PlanDeletion('Standard', Days::date('2027-03-08'), 'CEILING_RELEASE_DELETE')],
            [new ListingChange('Deluxe', ListingField::Min, 640.0, 'OWNER_ORDER', '', 'listing'), new ListingChange('Deluxe', ListingField::Max, 3000.0, 'OWNER_ORDER', '', 'listing')],
        ));
        $array = $payload->toArray();

        self::assertSame([['date' => '2027-03-09'], ['date' => '2027-03-08']], $array['deletions'][0]['overrides']);
        self::assertSame(EngineSample::deluxe()->pricingRef, $array['deletions'][1]['listing_id']);
        self::assertSame([['listing_id' => EngineSample::deluxe()->pricingRef, 'pms' => 'sample-pms', 'min' => 640.0, 'max' => 3000.0]], $array['listing']);
        self::assertFalse($payload->isEmpty());
    }

    public function testOnlyASealedPlanOfThePropertyBecomesAPayload(): void
    {
        $plan = self::validated([PlanSample::item()]);
        $other = new Property('other', 'Other', 'UTC', 'EUR');

        $this->expectException(InvalidArgumentException::class);
        (new PayloadBuilder('sample-pms'))->build($plan, $other, [EngineSample::deluxe()]);
    }

    public function testThePayloadNeedsThePmsNameAndTheRoomsOfThePlan(): void
    {
        $plan = self::validated([PlanSample::item()]);
        foreach ([static fn () => new PayloadBuilder(''), static fn () => (new PayloadBuilder('x'))->build($plan, EngineSample::property(), [EngineSample::standard()])] as $build) {
            try {
                $build();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSlicesKeepTonightAndTomorrowApartAndCutTheRest(): void
    {
        $items = [];
        foreach (range(0, 9) as $offset) {
            $night = Days::add(Days::date(PlanSample::TODAY), $offset);
            $items[] = PlanSample::item(['date' => $night, 'minPrice' => 1300.0, 'before' => new NightState(1400.0)]);
            $items[] = PlanSample::item(['roomCode' => 'Standard', 'date' => $night, 'minPrice' => 400.0, 'before' => new NightState(450.0)]);
        }
        $payload = self::build(self::validated($items, [new PlanDeletion('Standard', Days::date('2027-03-05'), 'CEILING_RELEASE_DELETE')], [new ListingChange('Deluxe', ListingField::Min, 640.0, 'OWNER_ORDER', '', 'listing')]));

        $slices = (new Slicer(3))->slice($payload, Days::date(PlanSample::TODAY));
        $summary = array_map(static fn (Slice $s): string => sprintf('%d %s %s %s %s..%s %d', $s->number, $s->kind->value, $s->roomCode(), $s->label, $s->first() === null ? '-' : Days::key($s->first()), $s->last() === null ? '-' : Days::key($s->last()), count($s->dates())), $slices);

        self::assertSame([
            '1 delete Standard 1/1 2027-03-05..2027-03-05 1',
            '2 write Deluxe D0 2027-03-03..2027-03-03 1',
            '3 write Standard D0 2027-03-03..2027-03-03 1',
            '4 write Deluxe D1 2027-03-04..2027-03-04 1',
            '5 write Standard D1 2027-03-04..2027-03-04 1',
            '6 write Deluxe 1/3 2027-03-05..2027-03-07 3',
            '7 write Deluxe 2/3 2027-03-08..2027-03-10 3',
            '8 write Deluxe 3/3 2027-03-11..2027-03-12 2',
            '9 write Standard 1/3 2027-03-05..2027-03-07 3',
            '10 write Standard 2/3 2027-03-08..2027-03-10 3',
            '11 write Standard 3/3 2027-03-11..2027-03-12 2',
            '12 listing Deluxe listing -..- 0',
        ], $summary);
        self::assertSame(CallKind::Listing, $slices[11]->kind);
        self::assertSame('2027-03-05', $slices[5]->toArray()['call']['overrides'][0]['date']);
    }

    public function testASliceNeverRepeatsADateAndCarriesAtLeastOne(): void
    {
        $call = new RoomCall(CallKind::Delete, 'Deluxe', 'pricing-1', 'pms', [Days::date('2027-03-05'), Days::date('2027-03-05')]);
        foreach ([static fn () => new Slicer(0), static fn () => (new Slicer())->slice(new Payload([$call], [], []), Days::date(PlanSample::TODAY))] as $slice) {
            try {
                $slice();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new RoomCall(CallKind::Write, 'Deluxe', 'pricing-1', 'pms', [Days::date('2027-03-05')]);
    }
}
