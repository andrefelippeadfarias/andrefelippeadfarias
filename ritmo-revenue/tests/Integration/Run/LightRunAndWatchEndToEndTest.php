<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertStatus;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * The light run and the watch against the simulator: a new booking makes a light run decide only
 * its own nights; a booking without value and a booking far below the prices raise their alerts.
 * No guest data reaches an alert.
 */
final class LightRunAndWatchEndToEndTest extends TestCase
{
    public function testALightRunWithANewBookingDecidesOnlyTheNightsOfThatBooking(): void
    {
        $world = new RunWorld();
        self::assertSame(LaunchStatus::Completed, $world->run()->status, 'the full run reads first');
        $world->later('+1 hour');
        $world->apis->addBookings([self::booking(7101, 902001, '2027-03-20', '2027-03-22', 1100)]);
        $world->later('+1 hour');

        $light = $world->run(RunKind::Light);

        self::assertSame(LaunchStatus::Completed, $light->status, $light->note);
        $nights = [];
        foreach ($world->stack->planItems()->forRun((int) $light->runId) as $item) {
            if ($item['status'] === PlanItemStatus::WOULD_WRITE) {
                $nights[$item['room_code'] . '|' . $item['night']->format('Y-m-d')] = true;
            }
        }
        self::assertNotSame([], $nights, json_encode($world->summary($light->runId)['watch'] ?? []));
        self::assertSame([], array_diff(array_keys($nights), ['STD|2027-03-20', 'STD|2027-03-21']), 'only the nights of the new booking get a step');
        self::assertSame([], $world->writes());
    }

    public function testALightRunWithoutMovementDecidesNothingAndSaysSoInOneLine(): void
    {
        $world = new RunWorld();
        $world->run();
        $world->later('+3 hours');

        $light = $world->run(RunKind::Light);

        $summary = $world->summary($light->runId);
        self::assertSame(0, $summary['validate']['items'] ?? null);
        // The three sentences of M3 (nothing moved, nothing decided, writes sent: 0) are one coded line (reports spec 2.5).
        self::assertSame([['code' => 'light_idle', 'params' => []]], $summary['report']['items'] ?? null);
    }

    public function testABookingWithoutValueAndOneFarBelowThePricesRaiseTheirAlerts(): void
    {
        $world = new RunWorld(rules: ['alerts' => ['below_expected' => ['enabled' => true, 'price_basis' => 'current']]]);
        $world->run();
        $world->later('+1 hour');
        $world->apis->addBookings([
            self::booking(7102, 902002, '2027-03-24', '2027-03-26', 0),
            self::booking(7103, 902001, '2027-03-27', '2027-03-28', 90),
        ]);
        $world->later('+1 hour');

        $world->run();

        $ledger = new AlertLedger($world->db);
        $zero = $ledger->latestByKey('security_zero_value:inn:7102');
        self::assertNotNull($zero, 'the booking without value raised a critical alert');
        self::assertSame(AlertStatus::Open, $zero->status);
        $below = null;
        foreach ($world->db->select('SELECT dedupe_key FROM ' . $world->db->table('alerts') . " WHERE code = ?", [AlertCode::BookingBelowExpected->value]) as $row) {
            $below = $row['dedupe_key'];
        }
        self::assertNotNull($below, 'the booking far below the prices raised its alert');
        self::assertCount(0, $world->db->select('SELECT id FROM ' . $world->db->table('alerts') . " WHERE context LIKE ? OR message LIKE ?", ['%GUESTDATA%', '%GUESTDATA%']), 'no guest data in an alert');
    }

    /**
     * @return array<string, mixed>
     */
    private static function booking(int $id, int $room, string $arrival, string $departure, int $price): array
    {
        $at = '2027-03-03T12:30:00Z';

        return [
            'id' => $id, 'roomId' => $room, 'roomQty' => 1, 'status' => 'new', 'arrival' => $arrival, 'departure' => $departure,
            'price' => $price, 'channel' => 'booking', 'referer' => 'Booking.com', 'bookingTime' => $at, 'modifiedTime' => $at, 'cancelTime' => null,
            'invoiceItems' => [['id' => $id, 'type' => 'charge', 'qty' => 1, 'amount' => $price, 'lineTotal' => $price, 'invoiceeId' => null]],
        ];
    }
}
