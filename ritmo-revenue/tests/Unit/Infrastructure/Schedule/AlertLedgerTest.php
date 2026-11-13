<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\AlertStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Migration\DraftOps;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Schema\SchemaInspector;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;

final class AlertLedgerTest extends TestCase
{
    private static function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant);
    }

    private static function draft(AlertCode $code = AlertCode::Overbooked, string $night = '2026-11-20', ?string $evidence = null): AlertDraft
    {
        return new AlertDraft($code, 'inn', ['room-a', $night], 'A night is sold beyond the units of the room.', ['units_over' => 1], 'room-a', Days::date($night), $evidence === null ? null : self::at($evidence));
    }

    public function testTheDraftMigrationAddsTheLifecycleToAnExistingAlertsTable(): void
    {
        $db = PdoDatabase::inMemory();
        $store = new ArrayStore();
        (new Migrator($db, $store, Migrations::all()))->migrate();
        $old = (new AlertRepository($db, new MovableClock()))->raise('overbooked', AlertRepository::CRITICAL, 'Before the migration.', [], 'inn', 'room-a', null, 'overbooked:inn:room-a:2026-11-20');
        (new Migrator($db, $store, [...Migrations::all(), new DraftOps()]))->migrate();

        $columns = (new SchemaInspector($db))->columns('alerts');
        foreach (['evidence_at', 'acknowledged_at', 'acknowledged_by', 'resolved_by', 'notified_at', 'digested_at', 'occurrences'] as $column) {
            self::assertContains($column, $columns);
        }
        $record = (new AlertLedger($db))->find($old);
        self::assertSame(1, $record?->occurrences, 'rows of M1 read with the defaults');
    }

    public function testInsertRefreshAndLookupByKeyOverEveryStatus(): void
    {
        $ledger = new AlertLedger(OpsDatabase::create());
        $first = $ledger->insert(self::draft(evidence: '2026-11-12T10:00:00Z'), 5, self::at('2026-11-12T10:00:00Z'));
        $again = $ledger->refresh($first, self::draft(evidence: '2026-11-12T09:00:00Z'), 6, self::at('2026-11-12T11:00:00Z'));

        self::assertSame($first->id, $again->id);
        self::assertSame([2, 6, '2026-11-12T10:00:00+00:00'], [$again->occurrences, $again->runId, $again->evidenceAt?->format(DATE_ATOM)], 'the newest evidence stays');
        $ledger->resolve($first->id, null, self::at('2026-11-12T12:00:00Z'));
        self::assertSame(AlertStatus::Resolved, $ledger->latestByKey('overbooked:inn:room-a:2026-11-20')?->status);
        self::assertNull($ledger->latestByKey('overbooked:inn:room-a:2026-11-21'));
    }

    public function testActiveAlertsAcknowledgementAndResolution(): void
    {
        $ledger = new AlertLedger(OpsDatabase::create());
        $a = $ledger->insert(self::draft(), null, self::at('2026-11-12T10:00:00Z'));
        $b = $ledger->insert(self::draft(night: '2026-11-21'), null, self::at('2026-11-12T10:00:00Z'));
        $ledger->insert(self::draft(AlertCode::InventoryClosedWithoutBooking), null, self::at('2026-11-12T10:00:00Z'));

        $ledger->acknowledge($a->id, 9, self::at('2026-11-12T10:30:00Z'));
        $ledger->resolve($b->id, 9, self::at('2026-11-12T10:40:00Z'));

        $active = $ledger->active('inn', ['overbooked']);
        self::assertSame([$a->id], array_map(static fn (AlertRecord $r): int => $r->id, $active));
        self::assertSame(AlertStatus::Acknowledged, $active[0]->status);
        self::assertSame([], $ledger->active('inn', []));
        self::assertCount(2, $ledger->active('inn', ['overbooked', 'inventory_closed_without_booking']));
    }

    public function testMailBookkeeping(): void
    {
        $ledger = new AlertLedger(OpsDatabase::create());
        $critical = $ledger->insert(self::draft(), null, self::at('2026-11-12T10:00:00Z'));
        $warning = $ledger->insert(self::draft(AlertCode::InventoryClosedWithoutBooking), null, self::at('2026-11-12T10:00:00Z'));
        $ledger->insert(new AlertDraft(AlertCode::ApprovalExpired, 'inn', ['p1'], 'Expired.'), null, self::at('2026-11-12T10:00:00Z'));

        self::assertSame([$critical->id, $warning->id], array_map(static fn (AlertRecord $r): int => $r->id, $ledger->pendingDigest('inn')), 'information never goes in a digest');
        $ledger->markNotified($critical->id, self::at('2026-11-12T10:01:00Z'));
        self::assertSame(1, $ledger->notifiedSince('inn', self::at('2026-11-12T10:00:00Z')));
        self::assertSame(0, $ledger->notifiedSince('inn', self::at('2026-11-12T10:02:00Z')));
        $ledger->markDigested([$warning->id], self::at('2026-11-12T11:00:00Z'));
        self::assertSame([], $ledger->pendingDigest('inn'));
    }
}
