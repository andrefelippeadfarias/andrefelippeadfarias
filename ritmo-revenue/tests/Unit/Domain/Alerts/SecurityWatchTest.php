<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\SecurityConfig;
use Ritmo\Domain\Alerts\SecurityFindingKind;
use Ritmo\Domain\Alerts\SecurityWatch;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Write\Hold\CancellationWave;
use Ritmo\Tests\Unit\Domain\Operations\MovementDetectorTest;

/**
 * The plugin's own rules around the reference's: learning mode, switches, unmapped rooms (OD9).
 */
final class SecurityWatchTest extends TestCase
{
    private static function since(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-12T13:40:00Z');
    }

    public function testWhileTheOriginsAreNotConfirmedNothingIsAlertedAndTheOriginsAreListed(): void
    {
        $watch = new SecurityWatch(new SecurityConfig(true, [], false), new CancellationWave());
        $bookings = [MovementDetectorTest::booking('1'), MovementDetectorTest::booking('2', created: '2026-11-01T10:00:00Z')];

        self::assertSame([], $watch->inspect($bookings, self::since()));
        self::assertSame(['Booking.com'], $watch->originsSeen($bookings, self::since()), 'only the booking changed in the window');
    }

    public function testTheWatchCanBeTurnedOffAndZeroValueOnItsOwn(): void
    {
        $zero = [MovementDetectorTest::booking('3', total: 0.0)];

        self::assertSame([], (new SecurityWatch(new SecurityConfig(false), new CancellationWave()))->inspect($zero, self::since()));
        self::assertSame([], (new SecurityWatch(new SecurityConfig(true, [], false, false), new CancellationWave()))->inspect($zero, self::since()));
        $found = (new SecurityWatch(new SecurityConfig(), new CancellationWave()))->inspect($zero, self::since());
        self::assertSame(SecurityFindingKind::ZeroValue, $found[0]->kind);
    }

    public function testBookingsOfUnmappedRoomsAreOutsideTheWatch(): void
    {
        $watch = new SecurityWatch(new SecurityConfig(true, ['Booking.com'], true), new CancellationWave());
        $unmapped = MovementDetectorTest::booking('4', room: null, total: null, status: BookingStatus::Confirmed);

        self::assertSame([], $watch->inspect([$unmapped], self::since()));
    }
}
