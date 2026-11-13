<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Approval;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Approval\WaveHoldService;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Hold\WaveConfig;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Application\Verify\Capabilities;
use Ritmo\Tests\Unit\Application\Verify\VerifyDatabase;

/**
 * A wave opens the hold; newer waves widen it; only a person with the approval capability (or
 * the configured hours) releases it.
 */
final class WaveHoldServiceTest extends TestCase
{
    public function testAWaveHoldsThePropertyUntilAPersonReleasesIt(): void
    {
        $clock = new MovableClock('2027-03-03T12:00:00Z');
        $holds = new WaveHoldRepository(VerifyDatabase::create());
        $service = new WaveHoldService($holds, new Capabilities([3 => [CapabilityCheck::APPROVE]]), $clock);
        $since = new DateTimeImmutable('2027-03-03T06:00:00Z');

        self::assertNull($service->observe('inn', [self::cancel('1', '08:00')], $since));
        $hold = $service->observe('inn', [self::cancel('1', '08:00'), self::cancel('2', '08:30'), self::cancel('3', '09:00', '2027-03-12')], $since);
        self::assertNotNull($hold);
        self::assertTrue($hold->coversNight('Deluxe', Days::date('2027-03-12')));

        $wider = $service->observe('inn', [self::cancel('4', '11:00', '2027-03-20'), self::cancel('5', '11:10', '2027-03-20'), self::cancel('6', '11:20', '2027-03-20')], new DateTimeImmutable('2027-03-03T10:00:00Z'));
        self::assertSame($hold->id, $wider?->id, 'the open hold is widened');
        self::assertTrue($wider->coversNight('Deluxe', Days::date('2027-03-21')));
        self::assertTrue($wider->coversNight('Deluxe', Days::date('2027-03-12')));

        try {
            $service->release('inn', 9);
            self::fail('a user without the capability');
        } catch (ApprovalRefused $refused) {
            self::assertSame(ApprovalRefused::NO_CAPABILITY, $refused->reason);
        }
        self::assertTrue($service->release('inn', 3));
        self::assertNull($service->active('inn'));
    }

    public function testHoldHoursReleaseItByTime(): void
    {
        $clock = new MovableClock('2027-03-03T12:00:00Z');
        $service = new WaveHoldService(new WaveHoldRepository(VerifyDatabase::create()), new Capabilities(), $clock, new WaveConfig(holdHours: 4));

        self::assertNotNull($service->observe('inn', [self::cancel('1', '09:00'), self::cancel('2', '09:10'), self::cancel('3', '09:20')], new DateTimeImmutable('2027-03-03T06:00:00Z')));
        $clock->advance(3600);
        self::assertNotNull($service->active('inn'));
        $clock->advance(1200);
        self::assertNull($service->active('inn'), 'four hours after the last cancellation (09:20)');
    }

    private static function cancel(string $ref, string $time, string $arrival = '2027-03-10'): BookingRecord
    {
        $at = new DateTimeImmutable('2027-03-03T' . $time . ':00Z');

        return new BookingRecord($ref, 'Deluxe', Days::date($arrival), Days::add(Days::date($arrival), 2), BookingStatus::Cancelled, 'booking', '', 400.0, new DateTimeImmutable('2027-02-01T10:00:00Z'), $at, $at);
    }
}
