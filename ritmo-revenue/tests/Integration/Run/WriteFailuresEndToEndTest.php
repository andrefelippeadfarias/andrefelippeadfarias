<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Infrastructure\Db\Repository\RecalcAttemptRepository;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * Failures in the middle of an autonomous write, against the simulator: the write stops at the
 * first failure, and the next run reads the systems again and sends only what is missing.
 */
final class WriteFailuresEndToEndTest extends TestCase
{
    private const OVERRIDES = '/pricelabs/v1/listings/901001___902002/overrides';

    public function testACrashAfterSomeWritesIsResumedWithoutSendingTwiceWhatArrived(): void
    {
        $world = self::world();
        $world->apis->crashAfterWrites(4);

        $first = $world->run();

        self::assertSame(LaunchStatus::Failed, $first->status, 'the process "died" in the middle of the write');
        $before = self::sentDates($world);
        $world->later('+2 minutes');
        $second = $world->run();

        self::assertSame(LaunchStatus::Completed, $second->status, $second->note);
        $summary = $world->summary($second->runId);
        self::assertGreaterThan(0, $summary['watch']['settled'] ?? 0, 'the unsettled rows of the crash were read back first (by the watch, before anything is decided)');
        $after = array_slice(self::sentDates($world), count($before));
        foreach ($after as [$path, $date, $value]) {
            foreach ($before as [$p, $d, $v]) {
                self::assertFalse($p === $path && $d === $date && $v === $value, "$path $date was sent again with the same values");
            }
        }
        $third = $world->run();
        self::assertSame(0, $world->summary($third->runId)['write']['writes_sent'] ?? 0, 'nothing is left to send');
    }

    public function testA429InTheMiddleStopsTheWriteAndTheNextRunCompletesIt(): void
    {
        $world = self::world();
        $world->apis->fault('POST', self::OVERRIDES, 429);

        $first = $world->run();

        self::assertSame(LaunchStatus::Completed, $first->status, $first->note);
        $write = $world->summary($first->runId)['write'] ?? [];
        self::assertSame('rate_limited', $write['stop'] ?? null, json_encode($write));
        self::assertTrue($write['failed']);
        self::assertNotNull((new AlertLedger($world->db))->latestByKey('write_failed:inn:pricing'), 'the failure raised write_failed');
        $sentFirst = count($world->writes());

        $world->later('+2 minutes');
        $second = $world->run();
        $written = $world->summary($second->runId)['write'] ?? [];
        self::assertNull($written['stop'] ?? null, json_encode($written));
        self::assertGreaterThan(0, $written['writes_sent']);
        self::assertGreaterThan($sentFirst, count($world->writes()));
    }

    public function testTheEmergencySwitchEngagedBetweenSlicesStopsTheNextOne(): void
    {
        $world = self::world();
        $world->hook->afterWrite = static function (int $writes) use ($world): void {
            if ($writes === 4) {
                $world->switch->engage();
            }
        };

        $result = $world->run();

        self::assertSame(LaunchStatus::Completed, $result->status, $result->note);
        $summary = $world->summary($result->runId);
        $write = $summary['write'] ?? [];
        self::assertSame('kill_switch', $write['stop'] ?? null, json_encode($write));
        self::assertCount(4, $world->writes(), 'no request left after the switch');
        self::assertFalse($write['failed'], 'a stop by the switch is not a failure of the systems');
        self::assertGreaterThan(0, $summary['verify']['nights_confirmed'] ?? 0, 'what already went out is confirmed and recorded');

        $blocked = $world->run();
        self::assertSame(0, $world->summary($blocked->runId)['write']['writes_sent'] ?? null, 'under the switch the next run writes nothing');
        self::assertSame('shadow', $world->summary($blocked->runId)['write']['mode'] ?? null);
        self::assertCount(4, $world->writes());
    }

    public function testTheSwitchBetweenADeletionAndItsRewriteLeavesAGroupThatTheNextRunRepairsFirst(): void
    {
        $world = self::world();
        $world->hook->afterWrite = static function (int $writes) use ($world): void {
            if ($writes === 3) {
                $world->switch->engage();
            }
        };

        $stopped = $world->run();

        $write = $world->summary($stopped->runId)['write'] ?? [];
        self::assertSame('kill_switch', $write['stop'] ?? null);
        self::assertSame(1, $write['broken_groups'] ?? null, 'the deletion went out, its rewrite did not');
        $alert = (new AlertLedger($world->db))->latestByKey('write_failed:inn:pricing');
        self::assertSame(1, $alert?->context['groups_open'] ?? null);
        self::assertStringStartsWith('DELETE ', $world->writes()[2]);

        $world->switch->release();
        $world->later('+2 minutes');
        $repair = $world->run();

        $repaired = $world->summary($repair->runId)['write'] ?? [];
        self::assertTrue($repaired['repair'] ?? false, json_encode($repaired));
        self::assertGreaterThan(0, $repaired['writes_sent']);
        $after = array_values(array_filter(array_slice($world->writes(), 3), static fn (string $l): bool => !str_ends_with($l, '/refresh_listing')));
        self::assertSame('POST /pricelabs/v1/listings/901001___902001/overrides', $after[0] ?? '', 'the rewrite of the open group goes first');
    }

    /**
     * A recalculation that failed is not tried again before the next scheduled routine, even by a
     * new run started meanwhile (WRITE.md section 6, D6; cota_recalculo.py). Review finding of the
     * M3 correction phase: the plugin counted the "same run" from each run's own start.
     */
    public function testAFailedRecalculationIsNotTriedAgainByANewRunOfTheSameRoutine(): void
    {
        $world = self::world();
        $world->apis->fault('POST', '/pricelabs/v1/refresh_listing', 400);
        $world->run();
        $first = self::attempts($world);
        self::assertSame(['failure'], array_values(array_unique(array_column($first, 'outcome'))), 'the first recalculation failed and the write stopped there');
        $failed = $first[0]['listing'];

        $world->later('+15 minutes');
        $world->run();

        $again = array_filter(array_slice(self::attempts($world), count($first)), static fn (array $a): bool => $a['listing'] === $failed);
        self::assertSame([], array_values($again), 'the listing whose recalculation failed waits for the next routine');
    }

    /**
     * Every recalculation attempt recorded, oldest first.
     *
     * @return list<array{id: int, listing: string, outcome: string, at: \DateTimeImmutable, error: string|null, response_id: string}>
     */
    private static function attempts(RunWorld $world): array
    {
        return (new RecalcAttemptRepository($world->db))->since('pricelabs', new \DateTimeImmutable('2027-01-01T00:00:00Z'));
    }

    private static function world(): RunWorld
    {
        return new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]]);
    }

    /**
     * Every date rule sent to the pricing system so far: path, date and the values sent.
     *
     * @return list<array{string, string, string}>
     */
    private static function sentDates(RunWorld $world): array
    {
        $out = [];
        foreach ($world->apis->writeBodies() as $write) {
            foreach ($write['body']['overrides'] ?? [] as $override) {
                $values = $override;
                unset($values['date'], $values['reason']);
                $out[] = [$write['method'] . ' ' . $write['path'], (string) $override['date'], json_encode($values)];
            }
        }

        return $out;
    }
}
