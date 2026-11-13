<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Alerts;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Infrastructure\Schedule\OpsDatabase;

/**
 * Who is told, and when (O29; A24, A25).
 */
final class AlertNotifierTest extends TestCase
{
    private PdoDatabase $db;

    private MovableClock $clock;

    protected function setUp(): void
    {
        $this->db = OpsDatabase::create();
        $this->clock = new MovableClock('2026-11-12T13:00:00Z');
    }

    private static function failure(string $provider): AlertDraft
    {
        return OperationAlerts::writeFailed('inn', $provider, 429, 1, 3, 0, new \DateTimeImmutable('2026-11-12T12:59:00Z'));
    }

    public function testACriticalAlertIsMailedAtOnceWithoutGuestData(): void
    {
        $kit = new AlertsKit($this->db, $this->clock);
        $kit->service->raise(self::failure('pricing'));

        [$to, $subject, $body] = $kit->mailer->sent[0];
        self::assertSame(['owner@example.org'], $to);
        self::assertSame('[Ritmo Revenue] Critical alert: write_failed (inn)', $subject);
        self::assertStringContainsString('http status: 429', $body);
        self::assertNotNull($kit->ledger->latestByKey('write_failed:inn:pricing')?->notifiedAt);
    }

    public function testOverTheHourlyCapTheRestWaitsForTheDigest(): void
    {
        $kit = new AlertsKit($this->db, $this->clock, maxPerHour: 2);
        foreach (['a', 'b', 'c'] as $provider) {
            $kit->service->raise(self::failure($provider));
        }
        $kit->service->raise(new AlertDraft(AlertCode::RunLate, 'inn', [], 'A run started late.'));

        self::assertCount(2, $kit->mailer->sent);
        self::assertSame(2, $kit->notifier->sendDigest('inn'), 'the third failure and the warning');
        self::assertStringContainsString('Daily summary of 2 alerts', $kit->mailer->sent[2][1]);
        self::assertSame(0, $kit->notifier->sendDigest('inn'), 'a digest is sent once');
    }

    public function testAMailThatFailsIsLoggedAndNeverFailsTheRaise(): void
    {
        $kit = new AlertsKit($this->db, $this->clock);
        $kit->mailer->throws = true;
        $alert = $kit->service->raise(self::failure('pricing'));

        self::assertNotNull($alert);
        self::assertNull($kit->ledger->find($alert->id)?->notifiedAt);
        self::assertNotSame([], $kit->logger->entries('warning'));
        $kit->mailer->throws = false;
        $kit->mailer->works = false;
        self::assertSame(0, $kit->notifier->sendDigest('inn'), 'still waiting after a refused digest');
        $kit->mailer->works = true;
        self::assertSame(1, $kit->notifier->sendDigest('inn'));
    }

    public function testWithoutRecipientsNothingIsSent(): void
    {
        $kit = new AlertsKit($this->db, $this->clock, []);
        $kit->service->raise(self::failure('pricing'));

        self::assertSame([], $kit->mailer->sent);
        self::assertStringContainsString('no recipient', $kit->logger->dump());
    }
}
