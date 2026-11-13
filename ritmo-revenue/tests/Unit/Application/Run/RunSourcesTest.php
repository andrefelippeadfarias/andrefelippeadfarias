<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Run;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Run\WeeklyReference;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\AnchorRuler;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Store\ArrayStore;

/**
 * The sources the run wires that M2 left neutral (the market ruler, the weekly reference of the
 * listings) and the events of the operation kept in the change log.
 */
final class RunSourcesTest extends TestCase
{
    public function testTheMarketRulerOfARunIsTheAnchorsAsOneSeries(): void
    {
        $anchors = [
            '2027-03-05' => new MarketAnchor(Days::date('2027-03-05'), 400.0, 500.0, 600.0, 700.0, 900.0, 8, null, AnchorOrigin::Measured),
            '2027-03-06' => new MarketAnchor(Days::date('2027-03-06'), 0.0, 0.0, 0.0, 0.0, 0.0, 0, null, AnchorOrigin::Measured),
        ];

        $ruler = AnchorRuler::of($anchors, RuleSet::defaults());

        $night = $ruler->night(Days::date('2027-03-05'));
        self::assertNotNull($night);
        self::assertSame([400.0, 500.0, 600.0, 700.0, 900.0], array_map(static fn (float $p): ?float => $night->valueAt(AnchorRuler::SERIES, $p), [25.0, 50.0, 75.0, 90.0, 100.0]));
        self::assertSame(RuleSet::defaults()->nightType(Days::date('2027-03-05'))->value, $night->type);
        self::assertNull($ruler->night(Days::date('2027-03-06')), 'an anchor without a sample gives no ruler night');
        self::assertNull($ruler->night(Days::date('2027-03-07')));
    }

    public function testTheWeeklyReferenceKeepsTheFirstValuesOfTheWeek(): void
    {
        $weekly = new WeeklyReference(new ArrayStore());
        $monday = new DateTimeImmutable('2027-03-01');

        self::assertSame(['STD' => ['base' => 480.0, 'min' => 320.0]], $weekly->of('inn', ['STD' => ['base' => 480, 'min' => 320.0]], $monday));
        self::assertSame(['base' => 480.0, 'min' => 320.0], $weekly->of('inn', ['STD' => ['base' => 600.0, 'min' => 400.0], 'GDN' => ['base' => 700.0, 'min' => null]], Days::add($monday, 3))['STD'], 'later in the week the first values stay');
        self::assertSame(['base' => 700.0], $weekly->of('inn', [], Days::add($monday, 4))['GDN'], 'a room first read later in the week joins with what was read');
        self::assertSame(['base' => 600.0, 'min' => 400.0], $weekly->of('inn', ['STD' => ['base' => 600.0, 'min' => 400.0]], Days::add($monday, 7))['STD'], 'a new week starts again');
    }

    public function testOperationEventsLiveInTheChangeLogWithoutDisturbingTheNightChanges(): void
    {
        $db = PdoDatabase::inMemory();
        (new Migrator($db, new ArrayStore(), Migrations::all()))->migrate();
        $events = new OperationEventRepository($db);
        $at = new DateTimeImmutable('2027-03-03T12:00:00Z');

        $events->record('inn', OperationEventRepository::MODE, 'shadow', 'approval', 'ready', 'user:3', $at);
        $events->record('inn', OperationEventRepository::MODE, 'approval', 'auto', 'ready again', 'user:4', $at->modify('+1 day'));

        self::assertSame('user:4', $events->lastTo('inn', OperationEventRepository::MODE, 'auto')['actor'] ?? null);
        self::assertSame(['auto', 'approval'], array_column($events->latest('inn'), 'to'));
        self::assertNull($events->lastTo('inn', OperationEventRepository::MODE, 'shadow'));
        self::assertSame([], (new ChangeLogRepository($db))->latest('inn'), 'the night changes do not see the events');
        $this->expectException(\InvalidArgumentException::class);
        $events->record('inn', 'anything', 'a', 'b', '', 'system', $at);
    }
}
