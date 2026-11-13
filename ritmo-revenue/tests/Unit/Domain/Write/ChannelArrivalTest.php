<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Channel\ArrivalReport;
use Ritmo\Domain\Write\Channel\ChannelArrival;
use Ritmo\Domain\Write\Ledger\ConfirmedSteps;

/**
 * A step reaches the channel only with a push after it AND the PMS price of the step (task rule
 * 9(j)); the ledger only records steps confirmed stored.
 */
final class ChannelArrivalTest extends TestCase
{
    private const PUSH = '2027-03-03T14:00:00Z';

    public function testAStepIsDeliveredWithThePushAndThePmsPrice(): void
    {
        $report = self::check(['up' => self::step(StepDirection::Up, 900.0)], ['Deluxe|2027-03-10' => 895.0]);

        self::assertSame(['up'], array_keys($report->delivered));
        self::assertSame(self::PUSH, $report->delivered['up']->format('Y-m-d\TH:i:s\Z'));
    }

    public function testAPushWithoutTheNewPriceIsNotADelivery(): void
    {
        $report = self::check([
            'up' => self::step(StepDirection::Up, 900.0),
            'down' => self::step(StepDirection::Down, 800.0, 'Standard'),
        ], ['Deluxe|2027-03-10' => 850.0, 'Standard|2027-03-10' => 900.0]);

        self::assertSame([], $report->delivered);
        self::assertSame(['up' => ChannelArrival::PMS_PRICE_OUTSIDE_STEP, 'down' => ChannelArrival::PMS_PRICE_OUTSIDE_STEP], $report->pending);
    }

    public function testWithoutAPushAfterTheWriteOrAPmsPriceTheStepWaits(): void
    {
        $late = self::step(StepDirection::Up, 900.0, 'Deluxe', '2027-03-03T14:30:00Z');
        $report = self::check(['late' => $late, 'unread' => self::step(StepDirection::Down, 800.0, 'Standard')], []);

        self::assertSame(['late' => ChannelArrival::NO_PUSH_SINCE_WRITE, 'unread' => ChannelArrival::PMS_PRICE_UNKNOWN], $report->pending);
        self::assertSame([], $report->overdue);
    }

    public function testAStepStoredToTheMinuteNeedsItsWholeMinuteBeforeThePush(): void
    {
        $minute = new StepRecord('Deluxe', Days::date('2027-03-10'), 1000.0, 900.0, StepDirection::Down, 'behind', null, SoldBase::Room, null, new DateTimeImmutable('2027-03-03T13:59:00Z'), true);

        self::assertSame(['m' => ChannelArrival::NO_PUSH_SINCE_WRITE], self::check(['m' => $minute], ['Deluxe|2027-03-10' => 900.0], '2027-03-03T13:59:30Z')->pending);
        self::assertSame(['m'], array_keys(self::check(['m' => $minute], ['Deluxe|2027-03-10' => 900.0], '2027-03-03T13:59:59Z')->delivered));
    }

    public function testAStepPendingLongerThanTheLimitIsOverdue(): void
    {
        $old = self::step(StepDirection::Up, 900.0, 'Deluxe', '2027-03-01T10:00:00Z');
        $report = (new ChannelArrival(30))->check(['old' => $old], [], [], ['Deluxe' => 1000.0], new DateTimeImmutable('2027-03-03T12:00:00Z'));

        self::assertSame(['old'], $report->overdue);
    }

    public function testAStepAlreadyOnTheChannelIsLeftAlone(): void
    {
        $done = self::step(StepDirection::Up, 900.0)->onChannel(new DateTimeImmutable(self::PUSH));

        $report = self::check(['done' => $done], []);

        self::assertSame([[], []], [$report->delivered, $report->pending]);
    }

    public function testTheBaseOfEveryRoomIsNeeded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ChannelArrival())->check([self::step(StepDirection::Up, 900.0)], [], [], [], new DateTimeImmutable(self::PUSH));
    }

    public function testOnlyStepsConfirmedStoredReachTheLedger(): void
    {
        $proposal = static fn (string $room, ?StepDirection $direction): StepProposal => new StepProposal($room, Days::date('2027-03-10'), 'behind', $direction, 1000.0, 950.0, 950, 1, SoldBase::Room, 4);
        $records = ConfirmedSteps::records(
            [$proposal('Deluxe', StepDirection::Down), $proposal('Standard', StepDirection::Down), $proposal('Suite', null)],
            ['Deluxe|2027-03-10' => true, 'Suite|2027-03-10' => true],
            new DateTimeImmutable('2027-03-03T12:00:07.5Z'),
        );

        self::assertCount(1, $records, 'Standard was not stored; Suite is not a step');
        self::assertSame('Deluxe', $records[0]->roomCode);
        self::assertSame('2027-03-03T12:00:07+00:00', $records[0]->writtenAt->format(DATE_ATOM), 'stamped at the read-back, to the second');
        self::assertNull($records[0]->onChannelAt);
    }

    /**
     * @param array<string, StepRecord> $steps
     * @param array<string, float>      $prices
     */
    private static function check(array $steps, array $prices, string $push = self::PUSH): ArrivalReport
    {
        return (new ChannelArrival())->check(
            $steps,
            ['Deluxe' => new DateTimeImmutable($push), 'Standard' => new DateTimeImmutable($push)],
            $prices,
            ['Deluxe' => 1000.0, 'Standard' => 500.0],
            new DateTimeImmutable('2027-03-03T15:00:00Z'),
        );
    }

    private static function step(StepDirection $direction, float $to, string $room = 'Deluxe', string $at = '2027-03-03T12:00:05Z'): StepRecord
    {
        return new StepRecord($room, Days::date('2027-03-10'), 1000.0, $to, $direction, 'behind', null, SoldBase::Room, null, new DateTimeImmutable($at));
    }
}
