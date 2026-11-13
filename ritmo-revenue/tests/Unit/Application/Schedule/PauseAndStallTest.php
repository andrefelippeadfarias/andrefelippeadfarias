<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\PauseService;
use Ritmo\Application\Schedule\StallMonitor;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;

final class PauseAndStallTest extends TestCase
{
    private static function pauses(ScheduleWorld $world): PauseService
    {
        return new PauseService(new PropertyRepository($world->db, $world->clock), $world->states, $world->clock, $world->logger);
    }

    public function testAPauseIsRecordedWithItsAuthorAndACleanReason(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:00:00Z');
        self::pauses($world)->pause('inn', new DateTimeImmutable('2026-11-13T10:00:00Z'), "Works\x07 in the\nlobby", 7);

        $state = $world->states->get('inn');
        self::assertSame([true, 7, 'Works  in the lobby'], [$state->paused, $state->pausedBy, $state->pauseReason]);
        self::pauses($world)->resume('inn', 7);
        self::assertFalse($world->states->get('inn')->paused);
        self::assertCount(2, $world->logger->entries('info'));
    }

    public function testAPauseEndsInTheFutureOfAnExistingProperty(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:00:00Z');

        try {
            self::pauses($world)->pause('inn', new DateTimeImmutable('2026-11-12T09:00:00Z'), '', 1);
            self::fail('a pause ending in the past');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(OutOfBoundsException::class);
        self::pauses($world)->pause('nowhere', null, '', 1);
    }

    public function testS11APausedPropertyCanStillBeRunByAPerson(): void
    {
        // "Run now" goes through the run service, not the scheduler: the pause only stops slots.
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        self::pauses($world)->pause('inn', null, 'Owner away', 1);
        $world->tick();

        self::assertSame('skipped_paused', $world->slotStatuses()['2026-11-12T07:30|full']);
        self::assertSame([], $world->launcher->kinds());
    }

    public function testS17ADueSlotWithoutATickRaisesStalledAndTheNextTickResolvesIt(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->launcher->next = ['busy'];
        $world->tick();
        $monitor = new StallMonitor($world->store, $world->slots, $world->alerts->service, $world->clock);

        $world->at('2026-11-12T10:45:00Z');
        self::assertFalse($monitor->check(20));
        $world->at('2026-11-12T10:56:00Z');
        self::assertTrue($monitor->check(20));
        self::assertNotNull($world->alerts->ledger->latestByKey('scheduler_stalled:'));
        self::assertSame('2026-11-12T10:31:00Z', $monitor->heartbeat()?->format('Y-m-d\TH:i:s\Z'));

        $world->tick();
        self::assertFalse($world->alerts->ledger->latestByKey('scheduler_stalled:')?->status->isActive());
    }
}
