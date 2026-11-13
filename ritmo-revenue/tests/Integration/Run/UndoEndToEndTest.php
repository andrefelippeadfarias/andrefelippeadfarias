<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * "Undo" on the change log against the simulator: the undo is a plan that goes through the
 * validator, an approval by the person who asked and the write gate; the night is written back
 * to what it held and the change is marked undone.
 */
final class UndoEndToEndTest extends TestCase
{
    public function testAnUndoWritesTheNightBackAndMarksTheChangeUndone(): void
    {
        $world = new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]]);
        $run = $world->run();
        $changes = $world->stack->changeLog()->forRun((int) $run->runId);
        $change = self::firstFloorChange($changes, '2027-03-20');
        $world->later('+5 minutes');
        $writesBefore = count($world->writes());

        $result = $world->stack->undoWriter()->undo(RunWorld::PROPERTY, [(int) $change->id], RunWorld::OWNER, $world->stack->rules());

        self::assertSame('completed', $result['status'], json_encode($result));
        self::assertGreaterThan(0, $result['written'], json_encode($result));
        self::assertSame(1, $result['undone']);
        self::assertGreaterThan($writesBefore, count($world->writes()));
        $after = $world->stack->changeLog()->byIds([(int) $change->id])[0];
        self::assertNotNull($after->undoneAt);
        $undoRows = $world->stack->changeLog()->forRun((int) $result['run_id']);
        self::assertNotSame([], $undoRows, 'the undo itself is in the change log');
        self::assertStringStartsWith('user:', $undoRows[0]->actor);
    }

    public function testOnlyAPersonWhoApprovesCanUndo(): void
    {
        $world = new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]]);
        $run = $world->run();
        $change = self::firstFloorChange($world->stack->changeLog()->forRun((int) $run->runId), '2027-03-20');

        $this->expectException(ApprovalRefused::class);
        $world->stack->undoWriter()->undo(RunWorld::PROPERTY, [(int) $change->id], 42, $world->stack->rules());
    }

    /**
     * @param list<ChangeRecord> $changes
     */
    private static function firstFloorChange(array $changes, string $night): ChangeRecord
    {
        foreach ($changes as $change) {
            if ($change->night?->format('Y-m-d') === $night && $change->field === 'floor_pct') {
                return $change;
            }
        }
        self::fail('no floor change on ' . $night);
    }
}
