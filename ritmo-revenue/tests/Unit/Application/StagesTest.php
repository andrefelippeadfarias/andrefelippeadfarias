<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\ShadowRun\MeasureStage;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Application\ShadowRun\PlanRefused;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\StateCodec;
use Ritmo\Application\ShadowRun\ValidateStage;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;

/**
 * The validate and measure stages on their own, with hand-made state.
 */
final class StagesTest extends TestCase
{
    private ShadowRunWorld $world;

    protected function setUp(): void
    {
        $this->world = new ShadowRunWorld();
    }

    public function testRejectedItemsAreSetApartAndTheRestIsValidatedAgain(): void
    {
        $context = $this->context(['items' => [
            'STD|2027-03-10' => self::entry(new PlanItem('STD', Days::date('2027-03-10'), 441.0, 480.0, null, 'pace_step', [], new NightState(420.0))),
            'STD|2027-03-11' => self::entry(new PlanItem('STD', Days::date('2027-03-11'), 100.0, null, null, 'pace_step', [], new NightState(420.0))),
            'STE|2027-03-10' => self::entry(new PlanItem('STE', Days::date('2027-03-10'), 700.0, null, null, 'pace_step', [], new NightState(700.0, 700.0, null, null, 0.0))),
        ]]);

        $result = (new ValidateStage($this->world->clock))->run($context);
        $outcomes = $result->state['outcomes'];

        self::assertSame(PlanItemStatus::WOULD_WRITE, $outcomes['STD|2027-03-10']['status']);
        self::assertSame(-8, $outcomes['STD|2027-03-10']['min_percent'], '441 over a base of 480, rounded up');
        self::assertSame(PlanItemStatus::REJECTED, $outcomes['STD|2027-03-11']['status']);
        self::assertContains(PlanIssue::BELOW_SAFETY_LIMIT, array_column($outcomes['STD|2027-03-11']['issues'], 'code'));
        self::assertNotSame(PlanItemStatus::WOULD_WRITE, $outcomes['STE|2027-03-10']['status'], 'a floor already in force is not written again');
        self::assertSame(1, $result->summary[PlanItemStatus::WOULD_WRITE]);
        self::assertSame(1, $result->summary[PlanItemStatus::REJECTED]);
    }

    public function testAPlanMadeForAnotherDayIsRefusedAsAWhole(): void
    {
        $context = $this->context(['items' => [
            'STD|2027-03-10' => self::entry(new PlanItem('STD', Days::date('2027-03-10'), 441.0, null, null, 'pace_step', [], new NightState(420.0))),
        ]], '2027-02-28');

        $this->expectException(PlanRefused::class);
        $this->expectExceptionMessage('stale_plan');
        (new ValidateStage($this->world->clock))->run($context);
    }

    public function testAnEmptyPlanIsValid(): void
    {
        $result = (new ValidateStage($this->world->clock))->run($this->context([]));

        self::assertSame([], $result->state['outcomes']);
        self::assertSame(0, $result->summary['items']);
    }

    public function testAPushOlderThanThirtyHoursOrDisabledIsReported(): void
    {
        $now = $this->world->clock->now();
        $listing = static fn (bool $enabled, ?string $pushed): array => ['ref' => 'L', 'push_enabled' => $enabled, 'last_pushed_at' => $pushed];
        $context = $this->context(['listings' => [
            'STD' => $listing(true, $now->modify('-30 hours')->format(DATE_ATOM)),
            'STE' => $listing(true, $now->modify('-30 hours -1 second')->format(DATE_ATOM)),
            'LOFT' => $listing(true, null),
            'OFF' => $listing(false, $now->format(DATE_ATOM)),
        ]]);

        $push = (new MeasureStage($this->world->snapshots, new BookingRepository($this->world->db), $this->world->alerts, $this->world->clock))->run($context)->summary['push'];

        self::assertNull($push['STD']['problem'], 'exactly thirty hours is still on time');
        self::assertSame(30, $push['STD']['hours_since_push']);
        self::assertSame('push_stale', $push['STE']['problem']);
        self::assertSame('push_stale', $push['LOFT']['problem'], 'never pushed');
        self::assertSame('push_disabled', $push['OFF']['problem']);
    }

    public function testStateTravelsAsPlainArraysAndComesBackTheSame(): void
    {
        $item = new PlanItem('STD', Days::date('2027-03-10'), 441.0, 480.5, 2, 'pace_step', ['direction' => 'up'], new NightState(420.0, 380.0, null, 1, -10.0, 50.0));
        $back = StateCodec::toPlanItem(json_decode((string) json_encode(StateCodec::planItem($item)), true));
        self::assertEquals($item, $back);
        self::assertSame(StateCodec::planItem($item), StateCodec::planItem($back));

        $pricing = ShadowRunWorld::pricing();
        $night = $pricing->readPrices('L-STD', Days::date('2027-03-02'), Days::date('2027-03-02'))[0];
        self::assertEquals($night, StateCodec::toPriceNight(json_decode((string) json_encode(StateCodec::priceNight($night)), true)));
        $override = $pricing->readOverrides('L-STD', Days::date('2027-03-10'), Days::date('2027-03-10'))[0];
        self::assertEquals($override, StateCodec::toOverride(json_decode((string) json_encode(StateCodec::override($override)), true)));
        self::assertSame('L-STD', StateCodec::listing($pricing->listListings()[0])['ref']);
        self::assertNull(StateCodec::instant(''));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function context(array $state, string $today = '2027-03-01'): RunContext
    {
        $day = Days::date($today);

        return new RunContext(1, new Property('inn', 'Example Inn', ShadowRunWorld::TIMEZONE, 'BRL'), ShadowRunWorld::rooms(), RuleSet::defaults(), $day, DateRange::nights($day, 60), '', [], $state);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(PlanItem $item): array
    {
        return ['item' => StateCodec::planItem($item), 'trace' => [], 'pace_status' => 'on_pace', 'direction' => 'up'];
    }
}
