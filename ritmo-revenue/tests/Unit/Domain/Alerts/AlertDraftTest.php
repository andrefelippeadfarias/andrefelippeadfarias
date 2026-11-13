<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\AlertLifecycle;
use Ritmo\Domain\Alerts\AlertSeverity;
use Ritmo\Domain\Alerts\GuestDataGuard;
use Ritmo\Domain\Support\Days;

final class AlertDraftTest extends TestCase
{
    public function testTheKeyIsTheCodeThePropertyAndTheScope(): void
    {
        $draft = new AlertDraft(AlertCode::Overbooked, 'inn', ['Deluxe', '2026-11-20'], 'Sold beyond the units.', ['units_over' => 1], 'Deluxe', Days::date('2026-11-20'));

        self::assertSame('overbooked:inn:Deluxe:2026-11-20', $draft->dedupeKey(), 'the key of the existing shadow-run alerts');
        self::assertSame(AlertSeverity::Critical, $draft->severity);
        self::assertSame(AlertDraft::keyFor(AlertCode::Overbooked, 'inn', ['Deluxe', '2026-11-20']), $draft->dedupeKey());
    }

    public function testALongKeyEndsInAHash(): void
    {
        $key = (new AlertDraft(AlertCode::RunMissed, 'inn', [str_repeat('x', 300)], 'Missed.'))->dedupeKey();

        self::assertSame(AlertDraft::KEY_MAX, strlen($key));
        self::assertStringContainsString('#', $key);
    }

    public function testTheCatalogGivesSeverityAndLifecycle(): void
    {
        self::assertSame(AlertLifecycle::Condition, AlertCode::ConnectorDown->lifecycle());
        self::assertSame(AlertLifecycle::Event, AlertCode::WriteFailed->lifecycle());
        self::assertSame(AlertSeverity::Info, AlertCode::ApprovalPending->severity());
        self::assertTrue(AlertCode::SecurityZeroValue->needsApprover());
        self::assertFalse(AlertCode::Overbooked->needsApprover());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function guestData(): iterable
    {
        yield 'name' => [['guest_name' => 'x']];
        yield 'first name, any case' => [['firstName' => 'x']];
        yield 'email nested' => [['booking' => ['e-mail' => 'x']]];
        yield 'phone' => [['phone' => '1']];
        yield 'rate description' => [['rate_description' => 'x']];
        yield 'notes' => [['notes' => 'x']];
        yield 'free text' => [['detail' => str_repeat('x', GuestDataGuard::MAX_TEXT + 1)]];
        yield 'object' => [['when' => new \DateTimeImmutable('2026-11-12')]];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('guestData')]
    public function testGuestDataAndFreeTextNeverEnterAnAlert(array $context): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AlertDraft(AlertCode::SecurityUnknownOrigin, 'inn', ['1'], 'A booking.', $context);
    }

    public function testIdsChannelsOriginsAndAmountsAreAllowed(): void
    {
        $draft = new AlertDraft(AlertCode::SecurityUnknownOrigin, 'inn', ['1'], 'A booking.', ['booking_id' => '1', 'channel' => 'booking', 'origin' => 'api', 'total' => 10.5, 'booking_ids' => ['1', '2']]);

        self::assertSame('booking', $draft->context['channel']);
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AlertDraft(AlertCode::RunLate, 'inn', [], ' ');
    }
}
