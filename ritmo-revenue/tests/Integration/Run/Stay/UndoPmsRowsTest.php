<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run\Stay;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Run\UndoWriter;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * Regression: the undo has no write path for PMS values yet (D9), so it leaves the PMS rows out
 * (refusal pms_field) and never marks them undone while the PMS still holds the value; the
 * pricing rows asked with them are undone as usual.
 */
final class UndoPmsRowsTest extends TestCase
{
    public function testThePmsRowsAreLeftOutAndNeverMarkedUndone(): void
    {
        $world = StayWorld::auto(['closed_days' => [['rooms' => ['STD']] + StayWorld::CLOSED_SATURDAY]]);
        $run = $world->run();
        $rows = $world->stack->changeLog()->forRun((int) $run->runId);
        $pmsRows = array_values(array_filter($rows, static fn ($r): bool => $r->provider === Provider::Pms));
        $pricing = array_values(array_filter($rows, static fn ($r): bool => $r->provider === Provider::Pricing && $r->roomCode === 'STD' && $r->night?->format('Y-m-d') === '2027-03-20'));
        self::assertNotSame([], $pmsRows, 'the PMS writes are in the change log');
        self::assertNotSame([], $pricing);
        $world->later('+5 minutes');

        $result = $world->stack->undoWriter()->undo(RunWorld::PROPERTY, array_map(static fn ($r): int => (int) $r->id, [...$pricing, ...$pmsRows]), RunWorld::OWNER, $world->stack->rules());

        self::assertContains(UndoWriter::PMS_LEFT_OUT, $result['refused']);
        self::assertSame(count($pricing), $result['undone'], 'the pricing rows are undone');
        foreach ($world->stack->changeLog()->byIds(array_map(static fn ($r): int => (int) $r->id, $pmsRows)) as $row) {
            self::assertNull($row->undoneAt, 'a PMS row is never marked undone without a write');
        }
        self::assertSame('noCheckInOrCheckOut', StayWorld::pmsNight($world, '902001', '2027-03-06')['override'] ?? null);
    }

    public function testAnUndoOfOnlyPmsRowsWritesNothing(): void
    {
        $world = StayWorld::auto(['closed_days' => [['rooms' => ['STD']] + StayWorld::CLOSED_SATURDAY]]);
        $run = $world->run();
        $pmsRows = array_values(array_filter($world->stack->changeLog()->forRun((int) $run->runId), static fn ($r): bool => $r->provider === Provider::Pms));
        $writes = count($world->writes());
        $world->later('+5 minutes');

        $result = $world->stack->undoWriter()->undo(RunWorld::PROPERTY, array_map(static fn ($r): int => (int) $r->id, $pmsRows), RunWorld::OWNER, $world->stack->rules());

        self::assertSame(['written' => 0, 'undone' => 0, 'refused' => [UndoWriter::PMS_LEFT_OUT]], array_intersect_key($result, ['written' => 1, 'undone' => 1, 'refused' => 1]));
        self::assertCount($writes, $world->writes(), 'no write');
    }
}
