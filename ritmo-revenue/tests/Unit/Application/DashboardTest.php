<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Dashboard\OccupancyQuery;
use Ritmo\Application\Dashboard\OccupancySignal;
use Ritmo\Application\Dashboard\PropertyDashboard;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Tests\Fakes\StaticConnectorSource;

final class DashboardTest extends TestCase
{
    private ShadowRunWorld $world;

    protected function setUp(): void
    {
        $this->world = new ShadowRunWorld();
    }

    public function testTheTrafficLightComparesAWindowWithItsGoal(): void
    {
        self::assertSame(OccupancySignal::GREEN, OccupancySignal::classify(60.0, 60.0));
        self::assertSame(OccupancySignal::AMBER, OccupancySignal::classify(59.9, 60.0));
        self::assertSame(OccupancySignal::AMBER, OccupancySignal::classify(50.0, 60.0));
        self::assertSame(OccupancySignal::RED, OccupancySignal::classify(49.9, 60.0));
        self::assertSame(OccupancySignal::UNKNOWN, OccupancySignal::classify(null, 60.0));
    }

    public function testBeforeAnyRunTheDashboardHasNothingToShowButTheProperty(): void
    {
        $view = $this->dashboard()->build(ShadowRunWorld::PROPERTY);

        self::assertSame('Example Inn', $view['property']['name']);
        self::assertSame(OccupancySignal::UNKNOWN, $view['occupancy']['signal']);
        self::assertNull($view['occupancy']['captured_at']);
        self::assertNull($view['last_run']);
        self::assertSame([], $view['would_write']);
        self::assertSame([], $view['refused']);
        self::assertSame([], $view['plan_issues']);
        self::assertSame([], $view['alerts']);
    }

    public function testAfterAShadowRunItShowsOccupancyAlertsPushAndWhatWouldBeWritten(): void
    {
        $outcome = $this->world->service()->run(ShadowRunWorld::PROPERTY);

        $view = $this->dashboard()->build(ShadowRunWorld::PROPERTY);

        self::assertSame($outcome->runId, $view['last_run']['id'] ?? null);
        self::assertSame($outcome->runId, $view['last_completed']['id'] ?? null);
        self::assertSame('2027-03-01', $view['occupancy']['today']);
        self::assertNotNull($view['occupancy']['captured_at']);
        self::assertSame([7, 15, 30, 45, 60], array_keys($view['occupancy']['property']));
        self::assertSame(['LOFT', 'STD', 'STE'], self::sorted(array_keys($view['occupancy']['rooms'])));
        self::assertContains($view['occupancy']['signal'], [OccupancySignal::GREEN, OccupancySignal::AMBER, OccupancySignal::RED]);
        self::assertSame('push_disabled', $view['push']['STE']['problem']);
        self::assertCount(2, $view['alerts']);
        $summary = $this->world->runs->find((int) $outcome->runId)['summary'] ?? [];
        self::assertSame($summary['validate'][PlanItemStatus::WOULD_WRITE], $view['would_write_total'], 'one entry per room night');
        self::assertCount(min(PropertyDashboard::WOULD_WRITE_SHOWN, $view['would_write_total']), $view['would_write']);
        $first = $view['would_write'][0];
        self::assertSame(['room_code', 'night', 'status', 'current_price', 'floor', 'ceiling', 'min_stay', 'reason', 'order_id', 'relation_codes', 'rule_trace', 'issues'], array_keys($first));
        self::assertSame('PACE_STEP', $first['reason']);
        self::assertSame($summary['validate'][PlanItemStatus::REJECTED], count($view['refused']), 'every refused room night is listed');
        foreach ($view['refused'] as $refused) {
            self::assertContains('error', array_column($refused['issues'], 'severity'), 'with the errors of the validator');
        }
        self::assertSame($summary['validate']['plan_issues'], $view['plan_issues']);
        self::assertNotNull($first['floor']['proposed'] ?? null);
        self::assertIsInt($first['floor']['percent']);
        self::assertSame('pace_step', array_column($first['rule_trace'], 'code')[count($first['rule_trace']) - 1]);
        $keys = array_map(static fn (array $n): string => $n['night']->format('Y-m-d') . $n['room_code'], $view['would_write']);
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys, 'by night, then room');
    }

    public function testAFailedRunIsShownButTheListComesFromTheLastCompletedOne(): void
    {
        $completed = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        $this->world->clock->advance(3600);
        $failed = $this->world->service(new StaticConnectorSource(null, null))->run(ShadowRunWorld::PROPERTY);

        $view = $this->dashboard()->build(ShadowRunWorld::PROPERTY);

        self::assertSame($failed->runId, $view['last_run']['id'] ?? null);
        self::assertSame($completed->runId, $view['last_completed']['id'] ?? null);
        self::assertNotSame([], $view['would_write']);
    }

    public function testAnUnknownPropertyIsAnError(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->dashboard()->build('nowhere');
    }

    private function dashboard(): PropertyDashboard
    {
        $properties = new PropertyRepository($this->world->db, $this->world->clock);
        $occupancy = new OccupancyQuery($properties, new RoomTypeRepository($this->world->db, $this->world->clock), new RuleSetRepository($this->world->db, $this->world->clock), $this->world->snapshots, $this->world->clock);

        return new PropertyDashboard($properties, $occupancy, $this->world->runs, $this->world->planItems, $this->world->alerts);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
