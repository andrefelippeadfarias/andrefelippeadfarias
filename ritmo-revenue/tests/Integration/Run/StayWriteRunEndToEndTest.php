<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * The stay writes in a whole run against the simulator (stay spec 3.11): the PMS changes the stay
 * rules ask for are sealed with the plan and written through the gate after the pricing steps;
 * shadow writes nothing; no PMS body carries a price or reaches a booking route.
 */
final class StayWriteRunEndToEndTest extends TestCase
{
    /** Minimum stay 2 on every arrival weekday, with the stay writes on. */
    private const RULES = [
        'stay' => [
            'arrival_rule' => ['enabled' => true, 'by_weekday' => [2, 2, 2, 2, 2, 2, 2]],
            'write' => ['enabled' => true, 'horizon_days' => 14],
        ],
    ];

    public function testShadowSealsThePmsChangesAndWritesNothing(): void
    {
        $world = new RunWorld(PropertyMode::Shadow, self::RULES);

        $summary = $world->summary($world->run()->runId);

        self::assertGreaterThan(0, $summary['decide']['pms_would_write'] ?? 0, json_encode($summary['decide'] ?? []));
        self::assertSame([], $world->writes());
    }

    public function testAnAutonomousRunWritesThePmsChangesAfterThePricingSteps(): void
    {
        $world = new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]] + self::RULES);

        $summary = $world->summary($world->run()->runId);

        $writes = $world->writes();
        $pms = array_values(array_filter($writes, static fn (string $line): bool => str_contains($line, '/inventory/rooms/calendar')));
        self::assertNotSame([], $pms, json_encode([$summary['decide'] ?? [], $summary['write'] ?? [], $writes]));
        $firstPms = (int) array_search($pms[0], $writes, true);
        $pricing = array_keys(array_filter($writes, static fn (string $line): bool => !str_contains($line, '/inventory/')));
        self::assertNotSame([], $pricing, 'the run also writes prices');
        self::assertLessThan($firstPms, max($pricing), 'the PMS blocks come after every pricing step');
        self::assertGreaterThan(0, $summary['verify']['pms_nights_confirmed'] ?? 0, 'the PMS nights were read back as stored');
        foreach ($world->apis->writeBodies() as $write) {
            if (str_contains((string) $write['path'], '/inventory/')) {
                $body = json_encode($write['body']);
                self::assertDoesNotMatchRegularExpression('/price|booking/i', (string) $body, 'no price and no booking in a PMS body');
                self::assertStringContainsString('minStay', (string) $body);
            }
        }
    }

    public function testInApprovalModeOnlyTheApprovedPmsChangesAreWritten(): void
    {
        $world = new RunWorld(PropertyMode::Approval, self::RULES);
        $world->run();
        self::assertSame([], $world->writes(), 'nothing is written while the plan waits');
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        $pms = array_values(array_filter($pending[0]['entries'], static fn (PlanEntry $e): bool => $e->kind === PlanEntry::PMS));
        self::assertNotSame([], $pms, 'the approval lists the PMS changes');
        $chosen = array_slice($pms, 0, 2);

        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, array_map(static fn (PlanEntry $e): string => $e->key(), $chosen), new ApprovalConfig());
        $world->run(RunKind::ApprovedWrite);

        self::assertSame(['POST /beds24/v2/inventory/rooms/calendar'], $world->writes(), 'only the approved PMS changes; no price was approved');
        $nights = [];
        foreach ($world->apis->writeBodies() as $write) {
            foreach ((array) $write['body'] as $room) {
                self::assertSame(902001, (int) ($room['roomId'] ?? 0));
                foreach ((array) ($room['calendar'] ?? []) as $range) {
                    $nights[] = $range['from'] ?? null;
                }
            }
        }
        self::assertSame(array_map(static fn (PlanEntry $e): string => explode('|', $e->subject)[0], $chosen), $nights, 'exactly the two nights approved');
    }
}
