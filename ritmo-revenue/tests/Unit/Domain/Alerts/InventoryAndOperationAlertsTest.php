<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\AlertSeverity;
use Ritmo\Domain\Alerts\BelowExpectedFinding;
use Ritmo\Domain\Alerts\ClosedNight;
use Ritmo\Domain\Alerts\ClosedWithoutBooking;
use Ritmo\Domain\Alerts\InventoryAlerts;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Alerts\SecurityAlerts;
use Ritmo\Domain\Alerts\SecurityFinding;
use Ritmo\Domain\Alerts\SecurityFindingKind;
use Ritmo\Domain\Calendar\Stay\RestrictedNight;
use Ritmo\Domain\Calendar\Stay\RoomAudit;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Report\OverbookedNight;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Hold\Wave;
use Ritmo\Tests\Unit\Domain\Operations\MovementDetectorTest;

final class InventoryAndOperationAlertsTest extends TestCase
{
    private static function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant);
    }

    public function testNightsClosedWithoutABookingAreBlockedForThePlan(): void
    {
        $inventory = ['room-a' => [
            new InventoryNight(Days::date('2026-11-20'), 1),
            new InventoryNight(Days::date('2026-11-21'), 2),
            new InventoryNight(Days::date('2026-11-22'), 3, restriction: Restriction::Blackout),
            new InventoryNight(Days::date('2026-11-23'), -1),
        ]];
        $closed = ClosedWithoutBooking::find($inventory, ['room-a' => ['2026-11-20' => 1, '2026-11-23' => 4]], ['room-a' => 3]);

        self::assertSame(['2026-11-20 2/1', '2026-11-21 1/0', '2026-11-22 0/0'], array_map(static fn (ClosedNight $n): string => sprintf('%s %d/%d', Days::key($n->night), $n->offSale, $n->booked), $closed));
        self::assertSame(['room-a|2026-11-20' => true, 'room-a|2026-11-21' => true, 'room-a|2026-11-22' => true], ClosedWithoutBooking::blocked($closed));
        $draft = InventoryAlerts::closedWithoutBooking('inn', $closed)[2];
        self::assertSame('inventory_closed_without_booking:inn:room-a:2026-11-22', $draft->dedupeKey());
        self::assertTrue($draft->context['blackout']);
    }

    public function testOverbookedNightsAreCriticalWithTheShadowRunKey(): void
    {
        $draft = InventoryAlerts::overbooked('inn', [new OverbookedNight('room-a', Days::date('2026-11-23'), -2)])[0];

        self::assertSame('overbooked:inn:room-a:2026-11-23', $draft->dedupeKey());
        self::assertSame(AlertSeverity::Critical, $draft->severity);
        self::assertSame(['units_over' => 2], $draft->context);
    }

    public function testAnAuditGivesOneAlertPerRoomAndKindWithTheNightsListed(): void
    {
        $audit = new RoomAudit('room-a', [], [Days::date('2026-11-21'), Days::date('2026-11-20')], [new RestrictedNight(Days::date('2026-11-25'), Restriction::NoCheckout)], [], [], []);
        $drafts = InventoryAlerts::audit('inn', [$audit], 1);

        self::assertSame(['inventory_stay_off_rule:inn:room-a:stay', 'inventory_arrival_off_rule:inn:room-a:other_restricted'], array_map(static fn (AlertDraft $d): string => $d->dedupeKey(), $drafts));
        self::assertSame(['finding' => 'stay', 'count' => 2, 'nights' => ['2026-11-20']], $drafts[0]->context, 'at most the configured number of nights');
        self::assertSame(AlertSeverity::Info, $drafts[1]->severity);
    }

    public function testSecurityAlertsCarryTheBookingByIdAndTheBurstByItsMembers(): void
    {
        $booking = MovementDetectorTest::booking('77', total: 0.0);
        $wave = new Wave('booking', ['1', '2', '3'], self::at('2026-11-12T14:00:00Z'), self::at('2026-11-12T15:00:00Z'), []);
        $drafts = SecurityAlerts::fromFindings('inn', [SecurityFinding::booking(SecurityFindingKind::ZeroValue, $booking), SecurityFinding::burst($wave)]);

        self::assertSame('security_zero_value:inn:77', $drafts[0]->dedupeKey());
        self::assertSame(['booking_id', 'room', 'arrival', 'departure', 'status', 'channel', 'origin', 'total', 'created_at'], array_keys($drafts[0]->context));
        self::assertSame(AlertCode::SecurityCancellationBurst, $drafts[1]->code);
        self::assertSame(['1', '2', '3'], $drafts[1]->context['booking_ids']);
        self::assertEquals(self::at('2026-11-12T15:00:00Z'), $drafts[1]->evidenceAt);

        $below = SecurityAlerts::belowExpected('inn', [new BelowExpectedFinding($booking, 2, 300.0, 980.0, 0.306, AlertSeverity::Critical)]);
        self::assertSame(AlertSeverity::Critical, $below[0]->severity);
        self::assertSame(0.306, $below[0]->context['ratio']);
    }

    public function testOperationAlertsCarryFactsOnly(): void
    {
        $write = OperationAlerts::writeFailed('inn', 'pricing', null, 2, 5, 1, self::at('2026-11-12T14:00:00Z'));
        self::assertSame(['provider' => 'pricing', 'http_status' => null, 'failure' => 'timeout', 'slice' => 2, 'entries_left' => 5, 'groups_open' => 1], $write->context);

        $pms = OperationAlerts::connectorDown('inn', 'pms', true, true);
        self::assertSame(AlertSeverity::Critical, $pms->severity);
        self::assertStringContainsString('Vigilance blind', $pms->message);
        self::assertSame('auth_failed', $pms->context['reason']);
        self::assertSame(AlertSeverity::Warning, OperationAlerts::connectorDown('inn', 'pricing', false, false)->severity);

        $manual = OperationAlerts::manualChange('inn', 'pricing', 'room-a', Days::date('2026-11-20'), ['floor' => 300], ['floor' => 350], self::at('2026-11-12T14:00:00Z'));
        self::assertSame('manual_change_detected:inn:room-a:2026-11-20:pricing', $manual->dedupeKey());
        self::assertSame('scheduler_stalled:', OperationAlerts::schedulerStalled(null, self::at('2026-11-12T14:00:00Z'))->dedupeKey());
        self::assertSame(['slot' => '2026-11-12T07:30', 'delay_minutes' => 35], OperationAlerts::runLate('inn', '2026-11-12T07:30', 2100)->context);
        self::assertSame('2026-11-10T10:30:00Z', OperationAlerts::runMissed('inn', self::at('2026-11-10T10:30:00Z'), self::at('2026-11-11T21:30:00Z'))->context['from']);
        self::assertSame(AlertCode::VigilanceGap, OperationAlerts::vigilanceGap('inn', self::at('2026-09-01T00:00:00Z'), self::at('2026-10-13T00:00:00Z'))->code);
        self::assertSame(AlertCode::ListingChangedManually, OperationAlerts::listingChanged('inn', 'L1', ['base' => 500], ['base' => 450], self::at('2026-11-12T14:00:00Z'))->code);
        self::assertSame(['room' => 'room-a'], OperationAlerts::recalcQuotaExhausted('inn', 'room-a')->context);
    }
}
