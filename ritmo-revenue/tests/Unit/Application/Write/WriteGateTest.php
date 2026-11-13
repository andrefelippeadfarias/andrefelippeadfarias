<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use DateTimeImmutable;
use Error;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Application\Write\Gate\AutonomousAuthorization;
use Ritmo\Application\Write\Gate\HumanApproval;
use Ritmo\Application\Write\Gate\PlanHash;
use Ritmo\Application\Write\Gate\WriteContext;
use Ritmo\Application\Write\Gate\WriteRefused;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Domain\Write\WriteConfig;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Contracts\WriteRequest;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Fakes\MovableClock;

/**
 * The write gate: every check before a token is issued (G1-G12 of WRITE.md), and the live
 * registry the connectors and the HTTP guard ask before every write.
 */
final class WriteGateTest extends TestCase
{
    private MovableClock $clock;

    protected function setUp(): void
    {
        $this->clock = WriteSample::clock();
    }

    private function auto(): AutonomousAuthorization
    {
        return new AutonomousAuthorization('inn', 7, new DateTimeImmutable('2027-03-01T10:00:00Z'));
    }

    private function plan(): ValidatedPlan
    {
        return WriteSample::validated($this->clock, [WriteSample::item(['minPrice' => 900.0, 'reasonText' => 'pace step'])]);
    }

    private function refusal(callable $issue): string
    {
        try {
            $issue();
        } catch (WriteRefused $refused) {
            return $refused->reason;
        }
        self::fail('The gate issued a token.');
    }

    public function testATokenCoversExactlyTheStepsOfTheValidatedPlan(): void
    {
        $gate = WriteSample::gate($this->clock);
        $issued = $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock));

        self::assertCount(1, $issued->steps);
        self::assertSame(WriteOperation::OverridesWrite, $issued->steps[0]->operation);
        self::assertSame(['pricing-1' => ['2027-03-10']], $issued->steps[0]->nights);
        self::assertSame('autonomous', $issued->actor);
        self::assertTrue($gate->isLive($issued->token));

        $gate->verify($issued->token, $issued->steps[0]->request());
        $this->expectException(WriteBlocked::class);
        $gate->verify($issued->token, $issued->steps[0]->request());
    }

    public function testAPlanTheValidatorDidNotSealIsRefused(): void
    {
        $forged = (new ReflectionClass(ValidatedPlan::class))->newInstanceWithoutConstructor();

        self::assertSame(WriteRefused::UNSEALED_PLAN, $this->refusal(fn () => WriteSample::gate($this->clock)->issue($forged, $this->auto(), WriteSample::context($this->clock))));
    }

    public function testAPlanOfAnotherDayIsNeverWritten(): void
    {
        $plan = $this->plan();
        $this->clock->advance(12 * 3600);

        self::assertSame(WriteRefused::STALE_PLAN, $this->refusal(fn () => WriteSample::gate($this->clock)->issue($plan, $this->auto(), WriteSample::context($this->clock))));
    }

    public function testAnOldValidationOrOldReadsAreRefused(): void
    {
        $plan = $this->plan();
        $this->clock->advance(11 * 60);
        self::assertSame(WriteRefused::STALE_VALIDATION, $this->refusal(fn () => WriteSample::gate($this->clock)->issue($plan, $this->auto(), WriteSample::context($this->clock))));

        $fresh = $this->plan();
        $old = $this->clock->now()->modify('-31 minutes');
        self::assertSame(WriteRefused::STALE_READS, $this->refusal(fn () => WriteSample::gate($this->clock)->issue($fresh, $this->auto(), WriteSample::context($this->clock, readAt: $old))));
        $future = $this->clock->now()->modify('+1 minute');
        self::assertSame(WriteRefused::STALE_READS, $this->refusal(fn () => WriteSample::gate($this->clock)->issue($fresh, $this->auto(), WriteSample::context($this->clock, readAt: $future))));
    }

    /**
     * The percentages of a plan are over the bases it was validated with; a plan over another base
     * than the one the run read is refused (review finding of the M3 correction phase).
     */
    public function testAPlanOverAnotherListingBaseThanTheOneReadIsRefused(): void
    {
        $plan = $this->plan();
        $rooms = array_map(static fn ($room) => $room->code === 'Deluxe' ? $room->withListing(null, $room->base * 1.6, $room->max * 2) : $room, WriteSample::rooms());
        $context = WriteSample::context($this->clock);
        $moved = new \Ritmo\Application\Write\Gate\WriteContext($context->property, $rooms, $context->readAt, $context->tonight, $context->lockHeld, $context->pricingPms);

        self::assertSame(WriteRefused::BASE_CHANGED, $this->refusal(fn () => WriteSample::gate($this->clock)->issue($plan, $this->auto(), $moved)));
    }

    public function testTheEmergencySwitchRefusesTheTokenAndEndsALiveOne(): void
    {
        $switch = WriteSample::switch();
        $gate = WriteSample::gate($this->clock, $switch);
        $issued = $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock));

        $switch->engage();

        self::assertFalse($gate->isLive($issued->token));
        self::assertSame(WriteRefused::KILL_SWITCH, $this->refusal(fn () => $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock))));
        $switch->release();
        self::assertFalse($gate->isLive($issued->token), 'a token stopped by the switch stays dead');
    }

    public function testTheModeMustAllowWriting(): void
    {
        $gate = WriteSample::gate($this->clock);
        $plan = $this->plan();

        self::assertSame(WriteRefused::MODE_SHADOW, $this->refusal(fn () => $gate->issue($plan, $this->auto(), WriteSample::context($this->clock, PropertyMode::Shadow))));
        self::assertSame(WriteRefused::MODE_NOT_AUTONOMOUS, $this->refusal(fn () => $gate->issue($plan, $this->auto(), WriteSample::context($this->clock, PropertyMode::Approval))));
        $noLicence = new WriteContext(WriteSample::property(), WriteSample::rooms(), $this->clock->now(), WriteSample::tonight(), static fn (): bool => true, 'sample-pms', false);
        self::assertSame(WriteRefused::LICENCE, $this->refusal(fn () => $gate->issue($plan, $this->auto(), $noLicence)));
    }

    public function testAPersonsApprovalMustBeOfThisPlanOfTodayByAnApproverNow(): void
    {
        $gate = WriteSample::gate($this->clock);
        $plan = $this->plan();
        $hash = PlanHash::of($plan->plan);
        $today = Days::date(WriteSample::TODAY);
        $approval = fn (string $hash, DateTimeImmutable $today): HumanApproval => new HumanApproval('a1', 'inn', $hash, $today, 3, $this->clock->now());
        $canApprove = static fn (int $user): bool => $user === 3;
        $context = WriteSample::context($this->clock, PropertyMode::Approval, canApprove: $canApprove);

        $issued = $gate->issue($plan, $approval($hash, $today), $context);
        self::assertSame('user:3', $issued->actor);

        self::assertSame(WriteRefused::APPROVAL_MISMATCH, $this->refusal(fn () => $gate->issue($plan, $approval(str_repeat('0', 64), $today), $context)));
        self::assertSame(WriteRefused::APPROVAL_STALE, $this->refusal(fn () => $gate->issue($plan, $approval($hash, Days::add($today, -1)), $context)));
        self::assertSame(WriteRefused::APPROVER_LACKS_CAPABILITY, $this->refusal(fn () => $gate->issue($plan, $approval($hash, $today), WriteSample::context($this->clock, PropertyMode::Approval))));
        self::assertSame(WriteRefused::MODE_SHADOW, $this->refusal(fn () => $gate->issue($plan, $approval($hash, $today), WriteSample::context($this->clock, PropertyMode::Shadow, canApprove: $canApprove))));
    }

    public function testAListingChangeNeedsAPersonNamingItAndIsNeverAutonomous(): void
    {
        $listing = [new ListingChange('Deluxe', ListingField::Base, 1050.0, 'owner', 'base change', 'listing')];
        $plan = WriteSample::validated($this->clock, [WriteSample::item(['minPrice' => 900.0])], [], $listing);
        $enabled = new WriteConfig(listingChangesEnabled: true);

        $auto = WriteSample::gate($this->clock, null, $enabled)->issue($plan, $this->auto(), WriteSample::context($this->clock));
        self::assertCount(1, $auto->steps, 'the date rule goes on');
        self::assertSame(WriteRefused::LISTING_NEEDS_APPROVAL, $auto->dropped[0]['reason']);

        $context = WriteSample::context($this->clock, PropertyMode::Approval, canApprove: static fn (int $u): bool => true);
        $named = new HumanApproval('a2', 'inn', PlanHash::of($plan->plan), Days::date(WriteSample::TODAY), 3, $this->clock->now(), ['Deluxe|base']);
        $human = WriteSample::gate($this->clock, null, $enabled)->issue($plan, $named, $context);
        self::assertSame([WriteOperation::OverridesWrite, WriteOperation::ListingUpdate], array_map(static fn ($s) => $s->operation, $human->steps));

        $off = WriteSample::gate($this->clock)->issue($plan, $named, $context);
        self::assertCount(1, $off->steps, 'listing changes are off by default');

        $only = WriteSample::validated($this->clock, [], [], $listing);
        self::assertSame(WriteRefused::NOTHING_TO_WRITE, $this->refusal(fn () => WriteSample::gate($this->clock, null, $enabled)->issue($only, $this->auto(), WriteSample::context($this->clock))));
    }

    public function testTonightIsCheckedAgainWithTheFreshPrice(): void
    {
        $plan = WriteSample::validated($this->clock, [WriteSample::item(['date' => WriteSample::TODAY, 'minPrice' => 950.0, 'before' => new NightState(1000.0, 600.0, 3000.0, 1)])]);
        $gate = WriteSample::gate($this->clock);

        self::assertCount(1, $gate->issue($plan, $this->auto(), WriteSample::context($this->clock))->steps);
        $cheaper = WriteSample::context($this->clock, tonight: WriteSample::tonight(900.0));
        self::assertSame(WriteRefused::RAISE_TODAY_FLOOR, $this->refusal(fn () => $gate->issue($plan, $this->auto(), $cheaper)));
        $unknown = WriteSample::context($this->clock, tonight: []);
        self::assertSame(WriteRefused::TONIGHT_UNKNOWN, $this->refusal(fn () => $gate->issue($plan, $this->auto(), $unknown)));
    }

    public function testPmsValuesOutsideTheRoomAreRefused(): void
    {
        $gate = WriteSample::gate($this->clock);
        $night = Days::date('2027-03-12');
        $tooMany = [new CalendarChange('Deluxe', 'pms-1', $night, $night, available: 5)];
        $wrongRoom = [new CalendarChange('Deluxe', 'pms-2', $night, $night, minStay: 2)];

        self::assertSame(WriteRefused::FORBIDDEN_FIELD, $this->refusal(fn () => $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock), $tooMany)));
        self::assertSame(WriteRefused::FORBIDDEN_FIELD, $this->refusal(fn () => $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock), $wrongRoom)));
        $fine = [new CalendarChange('Deluxe', 'pms-1', $night, $night, minStay: 2, available: 4)];
        self::assertSame(WriteOperation::PmsCalendar, $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock), $fine)->steps[1]->operation);
    }

    public function testTheRunMustHoldTheWriteLock(): void
    {
        self::assertSame(WriteRefused::LOCK_NOT_HELD, $this->refusal(fn () => WriteSample::gate($this->clock)->issue($this->plan(), $this->auto(), WriteSample::context($this->clock, lockHeld: false))));
    }

    public function testATokenEndsWithItsLifetimeTheDayAndARevocation(): void
    {
        $gate = WriteSample::gate($this->clock);
        $first = $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock));
        $this->clock->advance(15 * 60);
        self::assertFalse($gate->isLive($first->token), 'lifetime of 15 minutes');

        $late = new MovableClock('2027-03-03T23:55:00Z');
        $lateGate = WriteSample::gate($late);
        $issued = $lateGate->issue(WriteSample::validated($late, [WriteSample::item(['minPrice' => 900.0])]), $this->auto(), WriteSample::context($late));
        self::assertEquals(new DateTimeImmutable('2027-03-04T00:00:00Z'), $issued->expiresAt, 'never past the property\'s midnight');

        $again = $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock));
        $gate->revoke($again->token);
        self::assertFalse($gate->isLive($again->token));
    }

    public function testARequestOutsideTheStepIsRefused(): void
    {
        $gate = WriteSample::gate($this->clock);
        $issued = $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock));
        $step = $issued->steps[0];
        $outside = [
            'other listing' => new WriteRequest(WriteOperation::OverridesWrite, ['pricing-2' => ['2027-03-10']], $step->fields()),
            'other night' => new WriteRequest(WriteOperation::OverridesWrite, ['pricing-1' => ['2027-03-11']], $step->fields()),
            'other field' => new WriteRequest(WriteOperation::OverridesWrite, ['pricing-1' => ['2027-03-10']], ['max_price']),
            'other operation' => new WriteRequest(WriteOperation::OverridesDelete, ['pricing-1' => ['2027-03-10']]),
            'no values' => new WriteRequest(WriteOperation::OverridesWrite, ['pricing-1' => ['2027-03-10']], $step->fields()),
            'other values' => new WriteRequest(WriteOperation::OverridesWrite, ['pricing-1' => ['2027-03-10']], $step->fields(), WriteRequest::entriesOf([['date' => '2027-03-10', 'min_price' => '300', 'min_price_type' => 'percent_base']])),
        ];
        foreach ($outside as $what => $request) {
            try {
                $gate->verify($issued->token, $request);
                self::fail($what . ' was allowed');
            } catch (WriteBlocked) {
                self::assertTrue($gate->isLive($issued->token), $what);
            }
        }
        $gate->verify($issued->token, $step->request());
    }

    /**
     * A live token alone never lets a write through the HTTP guard: only the request a connector
     * just used a step for (review finding of the M3 correction phase), and the same request once
     * more (a PMS token renewed after a 401).
     */
    public function testTheHttpGuardTakesOnlyTheWriteAStepWasUsedFor(): void
    {
        $gate = WriteSample::gate($this->clock);
        $issued = $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock));
        $url = 'https://api.example.test/v1/listings/pricing-1/overrides';

        self::assertFalse($gate->claim($issued->token, 'POST', $url, '{"a":1}'), 'no step was used');
        $gate->verify($issued->token, $issued->steps[0]->request());
        self::assertTrue($gate->claim($issued->token, 'POST', $url, '{"a":1}'));
        self::assertTrue($gate->claim($issued->token, 'POST', $url, '{"a":1}'), 'the same request once more');
        self::assertFalse($gate->claim($issued->token, 'POST', $url, '{"a":1}'), 'not a third time');
        self::assertFalse($gate->claim($issued->token, 'POST', $url, '{"a":2}'), 'another request needs another step');
    }

    public function testATokenMadeAnyOtherWayIsRefused(): void
    {
        $gate = WriteSample::gate($this->clock);
        $issued = $gate->issue($this->plan(), $this->auto(), WriteSample::context($this->clock));
        $forged = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();

        self::assertFalse($gate->isLive($forged));
        try {
            $gate->verify($forged, $issued->steps[0]->request());
            self::fail('a forged token was allowed');
        } catch (WriteBlocked) {
            self::assertTrue($gate->isLive($issued->token));
        }
        try {
            $copy = clone $issued->token;
            self::fail('a token was cloned: ' . $copy->id);
        } catch (Error $error) {
            self::assertStringContainsString('__clone', $error->getMessage());
        }
        $this->expectException(LogicException::class);
        serialize($issued->token);
    }
}
