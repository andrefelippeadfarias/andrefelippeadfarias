<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Db;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\QuotaLogRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;
use Ritmo\Tests\Unit\Infrastructure\Support\ManualClock;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

final class RunRepositoriesTest extends TestCase
{
    private PdoDatabase $db;

    private ManualClock $clock;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
        $this->clock = new ManualClock('2026-11-11T10:53:00Z');
    }

    public function testARunIsOpenedAndClosed(): void
    {
        $runs = new RunRepository($this->db);
        $started = new DateTimeImmutable('2026-11-11T10:53:00Z');

        $id = $runs->start('inn-1', 'full', PropertyMode::Shadow, 3, $started);

        $run = $runs->find($id);
        self::assertSame(
            ['id' => $id, 'property_id' => 'inn-1', 'kind' => 'full', 'mode' => PropertyMode::Shadow, 'status' => RunRepository::RUNNING, 'ruleset_version' => 3],
            array_intersect_key((array) $run, array_flip(['id', 'property_id', 'kind', 'mode', 'status', 'ruleset_version']))
        );
        self::assertSame('2026-11-11T10:53:00+00:00', $run['started_at']->format(DATE_ATOM));
        self::assertNull($run['finished_at']);
        self::assertSame([], $run['summary']);
        self::assertNull($run['error']);

        $runs->finish($id, RunRepository::COMPLETED, ['nights' => 60, 'proposed' => 12, 'written' => 0], $started->modify('+42 seconds'));

        $run = $runs->find($id);
        self::assertSame(RunRepository::COMPLETED, $run['status'] ?? null);
        self::assertSame(['nights' => 60, 'proposed' => 12, 'written' => 0], $run['summary'] ?? null);
        self::assertSame('2026-11-11T10:53:42+00:00', $run['finished_at']?->format(DATE_ATOM));
        self::assertNull($runs->find(999));
    }

    public function testAFailedRunKeepsItsError(): void
    {
        $runs = new RunRepository($this->db);
        $id = $runs->start('inn-1', 'light', PropertyMode::Shadow, null, new DateTimeImmutable('2026-11-11T13:53:00Z'));

        $runs->finish($id, RunRepository::FAILED, [], new DateTimeImmutable('2026-11-11T13:53:05Z'), 'The PMS did not answer.');

        self::assertSame(RunRepository::FAILED, $runs->find($id)['status'] ?? null);
        self::assertSame('The PMS did not answer.', $runs->find($id)['error'] ?? null);
        self::assertSame(['ruleset_version' => null], array_intersect_key((array) $runs->find($id), ['ruleset_version' => true]));
    }

    public function testARunEndsOnlyAsCompletedOrFailedAndMustExist(): void
    {
        $runs = new RunRepository($this->db);
        $id = $runs->start('inn-1', 'full', PropertyMode::Shadow, 1, new DateTimeImmutable('2026-11-11T10:53:00Z'));

        try {
            $runs->finish($id, RunRepository::RUNNING, [], new DateTimeImmutable('2026-11-11T10:54:00Z'));
            self::fail('A run cannot end as running.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(OutOfBoundsException::class);
        $runs->finish(999, RunRepository::COMPLETED, [], new DateTimeImmutable('2026-11-11T10:54:00Z'));
    }

    public function testTheLatestRunsComeNewestFirstPerProperty(): void
    {
        $runs = new RunRepository($this->db);
        $first = $runs->start('inn-1', 'full', PropertyMode::Shadow, 1, new DateTimeImmutable('2026-11-11T10:53:00Z'));
        $second = $runs->start('inn-2', 'full', PropertyMode::Shadow, 1, new DateTimeImmutable('2026-11-11T10:55:00Z'));
        $third = $runs->start('inn-1', 'light', PropertyMode::Shadow, 1, new DateTimeImmutable('2026-11-11T13:53:00Z'));

        self::assertSame([$third, $second, $first], array_column($runs->latest(), 'id'));
        self::assertSame([$third, $first], array_column($runs->latest('inn-1'), 'id'));
        self::assertSame([$third], array_column($runs->latest('inn-1', 1), 'id'));
        self::assertSame([], $runs->latest('inn-9'));
    }

    public function testPlanItemsOfARunAreStoredWithTheirReasons(): void
    {
        $items = new PlanItemRepository($this->db, $this->clock);

        $id = $items->add(7, 'inn-1', 'suite', Days::date('2026-12-24'), 'floor', 610.0, 655.5, 'pace_ahead', ['sold' => 2, 'curve' => 37.5, 'anchor' => ['p50' => 700.0]]);
        $items->add(7, 'inn-1', 'double', Days::date('2026-12-20'), 'ceiling', null, 900.0, '', [], PricePlan::PublicRate, 'discarded');
        $items->add(8, 'inn-1', 'suite', Days::date('2026-12-24'), 'floor', 655.5, 640.0);

        $plan = $items->forRun(7);
        self::assertSame(['double', 'suite'], array_column($plan, 'room_code'));
        self::assertSame(
            [
                'id' => $id, 'run_id' => 7, 'property_id' => 'inn-1', 'room_code' => 'suite', 'field' => 'floor', 'plan' => PricePlan::Sent,
                'current' => 610.0, 'proposed' => 655.5, 'reason' => 'pace_ahead',
                'details' => ['sold' => 2, 'curve' => 37.5, 'anchor' => ['p50' => 700.0]], 'status' => 'proposed',
            ],
            array_diff_key($plan[1], ['night' => true])
        );
        self::assertSame('2026-12-24', Days::key($plan[1]['night']));
        self::assertNull($plan[0]['current']);
        self::assertSame(PricePlan::PublicRate, $plan[0]['plan']);
        self::assertSame('discarded', $plan[0]['status']);

        self::assertSame([8, 7], array_column($items->forNight('suite', Days::date('2026-12-24')), 'run_id'));
        self::assertSame([8], array_column($items->forNight('suite', Days::date('2026-12-24'), 1), 'run_id'));
        self::assertSame([], $items->forRun(99));
    }

    public function testAWholePlanIsStoredAllOrNothing(): void
    {
        $items = new PlanItemRepository($this->db, $this->clock);
        $night = Days::date('2026-12-24');

        self::assertSame(2, $items->addAll(5, [
            ['propertyId' => 'inn-1', 'roomCode' => 'suite', 'night' => $night, 'field' => 'floor', 'current' => 1.0, 'proposed' => 2.0, 'reason' => 'rule_a'],
            ['propertyId' => 'inn-1', 'roomCode' => 'double', 'night' => $night, 'field' => 'floor', 'current' => 3.0, 'proposed' => null],
        ]));
        self::assertCount(2, $items->forRun(5));

        try {
            $items->addAll(6, [
                ['propertyId' => 'inn-1', 'roomCode' => 'suite', 'night' => $night, 'field' => 'floor', 'current' => 1.0, 'proposed' => 2.0],
                ['propertyId' => 'inn-1', 'roomCode' => 'suite', 'night' => $night, 'field' => 'not a field!', 'current' => 1.0, 'proposed' => 2.0],
            ]);
            self::fail('An invalid field must be refused.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $items->forRun(6));
    }

    public function testAnAlertIsRaisedSeenAndResolved(): void
    {
        $alerts = new AlertRepository($this->db, $this->clock);

        $id = $alerts->raise('booking_below_floor', AlertRepository::CRITICAL, 'A booking was sold below the floor.', ['floor' => 600.0, 'sold_at' => 540.0], 'inn-1', 'suite', Days::date('2026-12-24'), '', 12);

        $alert = $alerts->find($id);
        self::assertSame(
            ['id' => $id, 'property_id' => 'inn-1', 'room_code' => 'suite', 'code' => 'booking_below_floor', 'severity' => 'critical', 'message' => 'A booking was sold below the floor.', 'context' => ['floor' => 600.0, 'sold_at' => 540.0], 'status' => 'open', 'dedupe_key' => '', 'run_id' => 12],
            array_diff_key((array) $alert, array_flip(['night', 'created_at', 'updated_at', 'resolved_at']))
        );
        self::assertSame('2026-12-24', Days::key($alert['night']));
        self::assertNull($alert['resolved_at']);
        self::assertSame(1, $alerts->countOpen());
        self::assertSame(1, $alerts->countOpen('inn-1'));
        self::assertSame(0, $alerts->countOpen('inn-2'));

        $this->clock->advance(60);
        $alerts->acknowledge($id);
        self::assertSame(AlertRepository::ACKNOWLEDGED, $alerts->find($id)['status'] ?? null);
        self::assertSame(0, $alerts->countOpen());
        self::assertSame([$id], array_column($alerts->unresolved(), 'id'), 'an acknowledged alert is still unresolved');

        $this->clock->advance(60);
        $alerts->resolve($id);
        self::assertSame(AlertRepository::RESOLVED, $alerts->find($id)['status'] ?? null);
        self::assertSame('2026-11-11T10:55:00+00:00', $alerts->find($id)['resolved_at']?->format(DATE_ATOM));
        self::assertSame([], $alerts->unresolved());

        $alerts->acknowledge($id);
        self::assertSame(AlertRepository::RESOLVED, $alerts->find($id)['status'] ?? null, 'a resolved alert stays resolved');
        self::assertNull($alerts->find(999));
    }

    public function testAnOpenAlertWithTheSameKeyIsRefreshedInsteadOfRepeated(): void
    {
        $alerts = new AlertRepository($this->db, $this->clock);

        $first = $alerts->raise('sync_stale', AlertRepository::WARNING, 'Prices were not pushed today.', ['hours' => 5], 'inn-1', '', null, 'sync_stale:inn-1');
        $this->clock->advance(3 * 3600);
        $again = $alerts->raise('sync_stale', AlertRepository::CRITICAL, 'Prices were not pushed today.', ['hours' => 8], 'inn-1', '', null, 'sync_stale:inn-1');
        $this->clock->advance(60);
        $other = $alerts->raise('sync_stale', AlertRepository::WARNING, 'Prices were not pushed today.', ['hours' => 5], 'inn-2', '', null, 'sync_stale:inn-2');

        self::assertSame($first, $again);
        self::assertNotSame($first, $other);
        self::assertSame(['hours' => 8], $alerts->find($first)['context'] ?? null);
        self::assertSame('critical', $alerts->find($first)['severity'] ?? null);
        self::assertSame('2026-11-11T10:53:00+00:00', $alerts->find($first)['created_at']->format(DATE_ATOM));
        self::assertSame('2026-11-11T13:53:00+00:00', $alerts->find($first)['updated_at']->format(DATE_ATOM));
        self::assertSame([$other, $first], array_column($alerts->unresolved(), 'id'), 'most recently updated first');
        self::assertSame([$other], array_column($alerts->unresolved('inn-2'), 'id'));

        $alerts->resolve($first);
        $new = $alerts->raise('sync_stale', AlertRepository::WARNING, 'Prices were not pushed today.', [], 'inn-1', '', null, 'sync_stale:inn-1');
        self::assertNotSame($first, $new, 'after it is resolved, the condition raises a new alert');
    }

    public function testAnAlertNeedsAKnownSeverity(): void
    {
        $alerts = new AlertRepository($this->db, $this->clock);

        $this->expectException(InvalidArgumentException::class);
        $alerts->raise('anything', 'fatal', 'Message.');
    }

    public function testTheStepLedgerKeepsEveryStepOfANight(): void
    {
        $ledger = new StepLedgerRepository($this->db);
        $night = Days::date('2026-12-24');
        self::assertNull($ledger->lastStep('suite', $night));

        $first = $ledger->record('inn-1', 'suite', $night, StepLedgerRepository::UP, 600.0, 630.0, new DateTimeImmutable('2026-11-10T10:53:00Z'), 'ahead', 1, 2, 'room', 4);
        $second = $ledger->record('inn-1', 'suite', $night, StepLedgerRepository::DOWN, 630.0, 615.5, new DateTimeImmutable('2026-11-11T10:53:00Z'));
        $ledger->record('inn-1', 'suite', Days::date('2026-12-25'), StepLedgerRepository::UP, 1.0, 2.0, new DateTimeImmutable('2026-11-11T10:53:00Z'));
        $ledger->record('inn-1', 'double', $night, StepLedgerRepository::UP, 1.0, 2.0, new DateTimeImmutable('2026-11-11T10:53:00Z'));

        self::assertSame([$second, $first], array_column($ledger->forNight('suite', $night), 'id'));
        $last = $ledger->lastStep('suite', $night);
        self::assertSame(
            ['id' => $second, 'property_id' => 'inn-1', 'room_code' => 'suite', 'direction' => 'down', 'from' => 630.0, 'to' => 615.5, 'pace_status' => '', 'units_sold' => null, 'units_total' => null, 'count_basis' => 'room', 'reached_channel' => false, 'run_id' => null],
            array_diff_key((array) $last, array_flip(['night', 'recorded_at']))
        );
        self::assertSame('2026-11-11T10:53:00+00:00', $last['recorded_at']->format(DATE_ATOM));

        $older = $ledger->forNight('suite', $night)[1];
        self::assertSame(['ahead', 1, 2, 4], [$older['pace_status'], $older['units_sold'], $older['units_total'], $older['run_id']]);
    }

    public function testStepsAreMarkedAsSentUpToTheLastPushOfTheRoom(): void
    {
        $ledger = new StepLedgerRepository($this->db);
        $night = Days::date('2026-12-24');
        $ledger->record('inn-1', 'suite', $night, StepLedgerRepository::UP, 600.0, 630.0, new DateTimeImmutable('2026-11-11T07:53:00Z'));
        $ledger->record('inn-1', 'suite', $night, StepLedgerRepository::UP, 630.0, 660.0, new DateTimeImmutable('2026-11-11T13:53:00Z'));
        $ledger->record('inn-1', 'double', $night, StepLedgerRepository::UP, 1.0, 2.0, new DateTimeImmutable('2026-11-11T07:53:00Z'));

        self::assertSame(1, $ledger->markReachedChannel('suite', new DateTimeImmutable('2026-11-11T11:40:00Z')));
        self::assertSame(0, $ledger->markReachedChannel('suite', new DateTimeImmutable('2026-11-11T11:45:00Z')), 'already marked steps are not counted again');

        self::assertSame([false, true], array_column($ledger->forNight('suite', $night), 'reached_channel'));
        self::assertFalse($ledger->lastStep('double', $night)['reached_channel'] ?? true);
    }

    public function testAStepNeedsAKnownDirection(): void
    {
        $ledger = new StepLedgerRepository($this->db);

        $this->expectException(InvalidArgumentException::class);
        $ledger->record('inn-1', 'suite', Days::date('2026-12-24'), 'sideways', 1.0, 2.0, new DateTimeImmutable('2026-11-11T07:53:00Z'));
    }

    public function testTheQuotaLogCountsByScopeOrForTheWholeAccount(): void
    {
        $log = new QuotaLogRepository($this->db);
        $at = new DateTimeImmutable('2026-11-11T10:00:00Z');
        $log->add('pricing', 'recalculation', 'L-1', true, $at);
        $log->add('pricing', 'recalculation', 'L-1', false, $at->modify('+10 minutes'));
        $log->add('pricing', 'recalculation', 'L-2', true, $at->modify('+20 minutes'));
        $log->add('pricing', 'other', 'L-1', true, $at->modify('+20 minutes'));
        $log->add('pms', 'recalculation', 'L-1', true, $at->modify('+20 minutes'));

        self::assertCount(2, $log->instants('pricing', 'recalculation', 'L-1', $at));
        self::assertCount(1, $log->instants('pricing', 'recalculation', 'L-1', $at, false), 'exclusive of the instant itself');
        self::assertCount(3, $log->instants('pricing', 'recalculation', '', $at), 'the empty scope is the whole account');
        self::assertSame([], $log->instants('pricing', 'recalculation', 'L-3', $at));
        self::assertSame(
            ['2026-11-11T10:00:00+00:00', '2026-11-11T10:10:00+00:00'],
            array_map(static fn (DateTimeImmutable $instant): string => $instant->format(DATE_ATOM), $log->instants('pricing', 'recalculation', 'L-1', $at))
        );

        $recent = $log->recent(2);
        self::assertCount(2, $recent);
        self::assertSame('2026-11-11T10:20:00+00:00', $recent[0]['called_at']->format(DATE_ATOM));
        self::assertFalse($log->recent(50)[3]['succeeded']);

        self::assertSame(2, $log->purgeBefore($at->modify('+20 minutes')));
        self::assertCount(3, $log->recent());
    }

    public function testACallIsKeptToTheSecondRoundedUp(): void
    {
        $log = new QuotaLogRepository($this->db);
        $log->add('pricing', 'recalculation', 'L-1', true, new DateTimeImmutable('2026-11-11T10:00:00.000001Z'));
        $log->add('pricing', 'recalculation', 'L-1', true, new DateTimeImmutable('2026-11-11T10:00:05Z'));

        self::assertSame(
            ['2026-11-11 10:00:01', '2026-11-11 10:00:05'],
            array_column($this->db->select('SELECT called_at FROM wp_ritmo_api_quota_log ORDER BY id'), 'called_at')
        );
    }
}
