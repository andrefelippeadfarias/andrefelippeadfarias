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

    public function testTheOutcomesOfTheAssembledPlanAreCountedAndCarried(): void
    {
        $context = $this->context(['items' => [
            'STD|2027-03-10' => self::entry(PlanItemStatus::WOULD_WRITE, -8),
            'STD|2027-03-11' => self::entry(PlanItemStatus::REJECTED, null, [PlanIssue::BELOW_SAFETY_LIMIT]),
            'STE|2027-03-10' => self::entry(PlanItemStatus::REJECTED, null, [PlanIssue::NO_EFFECT]),
            'STE|2027-03-11' => self::entry(PlanItemStatus::NO_CHANGE, 0),
        ], 'refused' => null, 'plan_issues' => []]);

        $result = (new ValidateStage())->run($context);

        self::assertSame(-8, $result->state['outcomes']['STD|2027-03-10']['min_percent']);
        self::assertSame(['STD|2027-03-10', 'STD|2027-03-11', 'STE|2027-03-10', 'STE|2027-03-11'], array_keys($result->state['outcomes']));
        self::assertSame(['items' => 4, PlanItemStatus::WOULD_WRITE => 1, PlanItemStatus::NO_CHANGE => 1, PlanItemStatus::REJECTED => 2], array_intersect_key($result->summary, array_flip(['items', PlanItemStatus::WOULD_WRITE, PlanItemStatus::NO_CHANGE, PlanItemStatus::REJECTED])));
        self::assertSame([PlanIssue::BELOW_SAFETY_LIMIT => 1, PlanIssue::NO_EFFECT => 1], $result->summary['issues']);
        self::assertFalse($result->summary['refused']);
    }

    public function testAPlanMadeForAnotherDayIsRefusedAsAWhole(): void
    {
        $context = $this->context(['items' => ['STD|2027-03-10' => self::entry(PlanItemStatus::REJECTED, null, [PlanIssue::STALE_PLAN])], 'refused' => [PlanIssue::STALE_PLAN]]);

        $this->expectException(PlanRefused::class);
        $this->expectExceptionMessage('stale_plan');
        (new ValidateStage())->run($context);
    }

    public function testAPlanRefusedAsAWholeForAnotherReasonIsKeptWithItsIssues(): void
    {
        $context = $this->context([
            'items' => ['STD|2027-03-10' => self::entry(PlanItemStatus::REJECTED)],
            'refused' => ['band_above_reference'],
            'plan_issues' => [['severity' => PlanIssue::ERROR, 'code' => 'band_above_reference', 'message' => 'out of its band', 'room' => 'STD', 'night' => '2027-03-12']],
        ]);

        $result = (new ValidateStage())->run($context);

        self::assertTrue($result->summary['refused']);
        self::assertSame(['band_above_reference'], $result->summary['plan_issues']);
        self::assertSame(1, $result->summary[PlanItemStatus::REJECTED]);
    }

    public function testAnEmptyPlanIsValid(): void
    {
        $result = (new ValidateStage())->run($this->context([]));

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
     * An item of the decide stage with the outcome its validation gave.
     *
     * @param list<string> $codes issue codes of the item
     *
     * @return array<string, mixed>
     */
    private static function entry(string $status, ?int $minPercent = null, array $codes = []): array
    {
        $issues = array_map(static fn (string $code): array => ['severity' => PlanIssue::ERROR, 'code' => $code, 'message' => $code], $codes);

        return ['item' => [], 'trace' => [], 'pace_status' => 'on_pace', 'direction' => 'up', 'outcome' => ['status' => $status, 'min_percent' => $minPercent, 'max_percent' => null, 'issues' => $issues]];
    }
}
