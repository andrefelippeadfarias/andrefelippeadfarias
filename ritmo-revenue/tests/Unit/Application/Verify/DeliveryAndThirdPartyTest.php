<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Verify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Verify\DeliveryService;
use Ritmo\Application\Verify\ThirdPartyWatch;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\SyncStatus;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Channel\ChannelArrival;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\ThirdParty\Observation;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyConfig;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\NightObservationRepository;
use Ritmo\Infrastructure\Db\Repository\WrittenStepRepository;
use Ritmo\Tests\Fakes\InMemoryPmsConnector;
use Ritmo\Tests\Fakes\InMemoryPricingConnector;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * Delivery is marked only with the push and the PMS price (task rule 9(j)); a night changed by
 * someone else is frozen until a person releases it (task rule 9(l)).
 */
final class DeliveryAndThirdPartyTest extends TestCase
{
    private PdoDatabase $db;

    protected function setUp(): void
    {
        $this->db = VerifyDatabase::create();
    }

    public function testDeliveredStepsAreMarkedAndTheOthersWait(): void
    {
        $steps = new WrittenStepRepository($this->db);
        $step = static fn (string $night, float $to): StepRecord => new StepRecord('Deluxe', Days::date($night), 1000.0, $to, StepDirection::Down, 'behind', null, SoldBase::Room, null, new DateTimeImmutable('2027-03-03T12:00:05Z'));
        $first = $steps->record('inn', $step('2027-03-10', 900.0), 21, 'op-1');
        $second = $steps->record('inn', $step('2027-03-11', 900.0), 21, 'op-1');
        $pricing = new InMemoryPricingConnector([new ListingInfo('pricing-1', 'Deluxe', 600.0, 1000.0, 3000.0, 'EUR', new SyncStatus(true, new DateTimeImmutable('2027-03-03T14:00:00Z')))]);
        $pms = new InMemoryPmsConnector([], ['pms-1' => [
            new InventoryNight(Days::date('2027-03-10'), 3, null, null, pmsPrice: 905.0),
            new InventoryNight(Days::date('2027-03-11'), 3, null, null, pmsPrice: 1000.0),
        ]]);

        $report = (new DeliveryService($steps, $pricing, $pms))->confirm('inn', ['Deluxe' => EngineSample::deluxe()], new DateTimeImmutable('2027-03-03T15:00:00Z'));

        self::assertSame([$first], array_keys($report->delivered));
        self::assertSame([$second => ChannelArrival::PMS_PRICE_OUTSIDE_STEP], $report->pending);
        self::assertSame([$second], array_keys($steps->pending('inn')));
        $ledger = $steps->ledger('inn', Days::date('2027-03-10'), Days::date('2027-03-11'))->records();
        self::assertSame('2027-03-03T14:00:00+00:00', $ledger[0]->onChannelAt?->format(DATE_ATOM));
        self::assertNull($ledger[1]->onChannelAt);
    }

    public function testNothingIsReadWithoutPendingSteps(): void
    {
        $pricing = new InMemoryPricingConnector();
        $report = (new DeliveryService(new WrittenStepRepository($this->db), $pricing, new InMemoryPmsConnector()))->confirm('inn', ['Deluxe' => EngineSample::deluxe()], new DateTimeImmutable('2027-03-03T15:00:00Z'));

        self::assertSame([], $report->delivered);
        self::assertSame([], $pricing->calls());
    }

    public function testANightChangedByOthersIsFrozenUntilReleased(): void
    {
        $clock = new MovableClock('2027-03-03T12:00:00Z');
        $watch = new ThirdPartyWatch(new NightObservationRepository($this->db), new DetectedFreezeRepository($this->db), new Capabilities([3 => [CapabilityCheck::APPROVE]]), $clock, new ThirdPartyConfig(true));
        $read = static fn (int $floor, string $at): Observation => new Observation(Provider::Pricing, 'Deluxe', Days::date('2027-03-20'), new NightFields([ChangeField::FLOOR_PCT => $floor]), new DateTimeImmutable($at));

        $baseline = $watch->inspect(EngineSample::property(), [$read(-20, '2027-03-03T12:00:00Z')]);
        self::assertSame([[], []], [$baseline->changes, $baseline->orders]);

        $clock->advance(3600 * 5);
        $changed = $watch->inspect(EngineSample::property(), [$read(-5, '2027-03-03T17:00:00Z')], [], [], 22);
        self::assertCount(1, $changed->changes);
        self::assertCount(1, $changed->orders);
        self::assertNotNull((new OrderBook($changed->orders))->manualFreeze('Deluxe', Days::date('2027-03-20')));

        $clock->advance(3600 * 24 * 10);
        self::assertCount(1, $watch->orders(EngineSample::property()), 'until a person releases it (configured)');
        self::assertSame([], $watch->inspect(EngineSample::property(), [$read(-5, '2027-03-13T17:00:00Z')])->changes, 'no new change');

        $id = (int) $changed->freezes[0]->id;
        try {
            $watch->release($id, 9);
            self::fail('a user without the capability');
        } catch (ApprovalRefused) {
            self::addToAssertionCount(1);
        }
        self::assertTrue($watch->release($id, 3));
        self::assertSame([], $watch->orders(EngineSample::property()));
    }
}
