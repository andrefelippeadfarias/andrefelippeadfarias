<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Dashboard;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Dashboard\Alerts\AlertFilter;
use Ritmo\Application\Dashboard\Changes\ChangeFilter;
use Ritmo\Application\Dashboard\Changes\ChangeGroups;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\UndoAction;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Infrastructure\Db\Query\Screens\AlertListReader;
use Ritmo\Infrastructure\Db\Query\Screens\ChangeLogReader;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Tests\Unit\Application\Grid\ScreenWorld;

/**
 * The paged and filtered lists of the Alerts and Change log screens, on SQLite.
 */
final class ListsTest extends TestCase
{
    private ScreenWorld $world;

    protected function setUp(): void
    {
        $this->world = new ScreenWorld();
        $this->world->property();
    }

    public function testAlertsAreFilteredBySeverityStatusAndGroupAndPaged(): void
    {
        $alerts = $this->world->alerts();
        foreach (range(1, 60) as $n) {
            $alerts->raise('push_stale', 'warning', 'Stale.', [], 'inn', '', null, 'push:' . $n);
        }
        $critical = $alerts->raise('security_unknown_origin', 'critical', 'Unknown origin.', ['ref' => '9001'], 'inn', '', null, 'sec:1');
        $resolved = $alerts->raise('overbooked', 'critical', 'Over.', [], 'inn', 'A', Days::date('2026-11-20'), 'over:1');
        $alerts->resolve($resolved);
        $reader = new AlertListReader($this->world->db);

        self::assertSame(61, $reader->count(new AlertFilter('inn')));
        self::assertSame(60, $reader->count(new AlertFilter('inn', 'unresolved', 'warning')));
        self::assertSame(1, $reader->count(new AlertFilter('inn', 'unresolved', '', 'security')));
        self::assertSame(1, $reader->count(new AlertFilter('inn', 'resolved')));
        self::assertSame(62, $reader->count(new AlertFilter('inn', 'all')));
        self::assertCount(11, $reader->page(new AlertFilter('inn'), 50, 50));
        self::assertSame(['9001'], [$reader->find($critical)['context']['ref'] ?? null]);
        self::assertSame(1, $reader->countBySeverity('inn', ['critical']));
        self::assertSame(['severity' => 'warning', 'group' => 'security'], (new AlertFilter('inn', 'unresolved', 'warning', 'security'))->query());
        self::assertSame(0, $reader->count(new AlertFilter('inn', 'unresolved', '', '', Days::date('2026-11-14'))), 'raised before the date range');
    }

    public function testTheChangeLogIsFilteredPagedAndGroupedByRun(): void
    {
        $log = new ChangeLogRepository($this->world->raw);
        $now = $this->world->clock->now();
        foreach (range(1, 30) as $n) {
            $log->record($this->change('A', Days::add(Days::date('2026-11-13'), $n % 10), 'autonomous', 11, $now->modify(sprintf('-%d minutes', $n))));
        }
        $log->record($this->change('B', Days::date('2026-11-20'), 'user:3', 12, $now));
        $reader = new ChangeLogReader($this->world->db, new ChangeLogRepository($this->world->db));

        self::assertSame(31, $reader->count(new ChangeFilter('inn')));
        self::assertSame(1, $reader->count(new ChangeFilter('inn', room: 'B')));
        self::assertSame(1, $reader->count(new ChangeFilter('inn', who: 'person')));
        self::assertSame(30, $reader->count(new ChangeFilter('inn', who: 'autonomous')));
        self::assertSame(3, $reader->count(new ChangeFilter('inn', room: 'A', nightFrom: Days::date('2026-11-20'), nightTo: Days::date('2026-11-20'))));
        $page = $reader->page(new ChangeFilter('inn'), 0, 25);
        self::assertCount(25, $page);
        self::assertSame('B', $page[0]->roomCode, 'newest first');

        $groups = ChangeGroups::byRun($page);
        self::assertCount(2, $groups);
        self::assertSame(3, $groups[0]['user_id']);
        self::assertSame(24, count($groups[1]['rows']));
        self::assertNull(ChangeGroups::userId('autonomous'));
    }

    private function change(string $room, \DateTimeImmutable $night, string $actor, int $run, \DateTimeImmutable $at): ChangeRecord
    {
        return new ChangeRecord('inn', Provider::Pricing, $room, $night, 'floor_pct', -10, -20, UndoAction::fromArray(['kind' => 'write_fields', 'fields' => ['floor_pct' => -10]]), $actor, $at, 540.0, 480.0, 'PACE_STEP', '', null, $run);
    }
}
