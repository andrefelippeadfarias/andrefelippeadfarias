<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Alerts;

use DateTimeImmutable;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\AlertStatus;
use Ritmo\Domain\Alerts\InventoryAlerts;
use Ritmo\Domain\Report\OverbookedNight;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Infrastructure\Schedule\OpsDatabase;

/**
 * Dedupe, reopening and resolution of alerts (O26 to O28; A20 to A23).
 */
final class AlertServiceTest extends TestCase
{
    private PdoDatabase $db;

    private MovableClock $clock;

    private AlertsKit $kit;

    protected function setUp(): void
    {
        $this->db = OpsDatabase::create();
        $this->clock = new MovableClock('2026-11-12T13:00:00Z');
        $this->kit = new AlertsKit($this->db, $this->clock);
    }

    private static function overbooked(string $night): AlertDraft
    {
        return InventoryAlerts::overbooked('inn', [new OverbookedNight('room-a', Days::date($night), -1)])[0];
    }

    private static function unknownOrigin(string $evidence): AlertDraft
    {
        return new AlertDraft(AlertCode::SecurityUnknownOrigin, 'inn', ['42'], 'A booking from an unknown origin.', ['booking_id' => '42'], evidenceAt: new DateTimeImmutable($evidence));
    }

    public function testRaisingTheSameKeyRefreshesWithoutANewRowOrAnotherMail(): void
    {
        $first = $this->kit->service->raise(self::overbooked('2026-11-20'), 1);
        $this->kit->service->acknowledge($first?->id ?? 0, 9, false);
        $again = $this->kit->service->raise(self::overbooked('2026-11-20'), 2);

        self::assertSame(1, AlertsKit::rows($this->db));
        self::assertSame($first?->id, $again?->id);
        self::assertSame([AlertStatus::Acknowledged, 2, 2], [$again?->status, $again?->occurrences, $again?->runId]);
        self::assertCount(1, $this->kit->mailer->sent, 'one critical e-mail per episode');
    }

    public function testAConditionThatComesBackIsANewRow(): void
    {
        $first = $this->kit->service->raise(self::overbooked('2026-11-20'));
        $this->kit->service->settle('inn', [AlertCode::Overbooked], []);
        $this->clock->advance(3600);
        $again = $this->kit->service->raise(self::overbooked('2026-11-20'));

        self::assertNotSame($first?->id, $again?->id);
        self::assertSame(2, AlertsKit::rows($this->db));
        self::assertCount(2, $this->kit->mailer->sent, 'a new episode is e-mailed again');
    }

    public function testAResolvedEventComesBackOnlyWithNewerEvidence(): void
    {
        $first = $this->kit->service->raise(self::unknownOrigin('2026-11-12T12:00:00Z'));
        $this->kit->service->resolve($first?->id ?? 0, 9, true);

        self::assertNull($this->kit->service->raise(self::unknownOrigin('2026-11-12T12:00:00Z')), 'the same evidence: a person already dealt with it');
        $this->clock->advance(60);
        self::assertNotNull($this->kit->service->raise(self::unknownOrigin('2026-11-12T13:00:30Z')));
        self::assertSame(2, AlertsKit::rows($this->db));
    }

    public function testSettleResolvesOnlyTheConditionsTheRunCheckedAndDidNotFind(): void
    {
        $this->kit->service->raise(self::overbooked('2026-11-20'));
        $this->kit->service->raise(self::overbooked('2026-12-20'));
        $event = $this->kit->service->raise(self::unknownOrigin('2026-11-12T12:00:00Z'));
        $raised = $this->kit->service->raiseAll([self::overbooked('2026-11-21')]);

        $resolved = $this->kit->service->settle('inn', [AlertCode::Overbooked, AlertCode::SecurityUnknownOrigin], $raised, static fn (AlertRecord $a): bool => $a->night !== null && $a->night < Days::date('2026-12-01'));

        self::assertSame(1, $resolved, 'only the November night, the one the run checked');
        $open = array_map(static fn (AlertRecord $a): string => $a->dedupeKey, $this->kit->ledger->active('inn', ['overbooked', 'security_unknown_origin']));
        self::assertSame(['overbooked:inn:room-a:2026-12-20', 'security_unknown_origin:inn:42', 'overbooked:inn:room-a:2026-11-21'], $open);
        self::assertSame(AlertStatus::Open, $this->kit->ledger->find($event?->id ?? 0)?->status, 'events never resolve by themselves');
    }

    public function testResolveKeyEndsAnEventThatEndsByItself(): void
    {
        $key = AlertDraft::keyFor(AlertCode::RunLate, 'inn');
        self::assertFalse($this->kit->service->resolveKey($key));
        $this->kit->service->raise(new AlertDraft(AlertCode::RunLate, 'inn', [], 'Late.'));

        self::assertTrue($this->kit->service->resolveKey($key));
        self::assertFalse($this->kit->service->resolveKey($key));
    }

    public function testSecurityAlertsNeedAnApprover(): void
    {
        $alert = $this->kit->service->raise(self::unknownOrigin('2026-11-12T12:00:00Z'));

        try {
            $this->kit->service->acknowledge($alert?->id ?? 0, 3, false);
            self::fail('a viewer acknowledged a security alert');
        } catch (OutOfBoundsException) {
        }
        $this->kit->service->acknowledge($alert?->id ?? 0, 4, true);
        self::assertSame(AlertStatus::Acknowledged, $this->kit->ledger->find($alert?->id ?? 0)?->status);

        $this->expectException(OutOfBoundsException::class);
        $this->kit->service->resolve($alert?->id ?? 0, 3, false);
    }

    public function testAnUnknownAlertIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->kit->service->acknowledge(999, 1, true);
    }
}
