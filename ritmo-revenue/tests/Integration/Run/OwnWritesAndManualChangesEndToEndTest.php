<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Tests\Integration\Run\Support\RunWorld;
use Ritmo\Tests\Integration\Write\Support\SimWorld;

/**
 * The plugin tells its own writes from someone else's (task rule 9(l)) and never sends again from
 * old reads (9(e)). Review findings of the M3 correction phase:
 * - a night written by a deletion-and-rewrite group and then changed by hand was overwritten;
 * - a merged write over a rule with a fixed price was taken for a manual change;
 * - a process killed while writing was continued from the same reads, sending everything again.
 */
final class OwnWritesAndManualChangesEndToEndTest extends TestCase
{
    /** The night the first autonomous run writes by deleting its rule and writing it again. */
    private const GROUP_NIGHT = '2027-03-05';

    public function testAManualChangeOnANightWrittenByAGroupIsFrozenAndNeverOverwritten(): void
    {
        $world = self::world();
        $world->run();
        self::assertContains('DELETE /pricelabs/v1/listings/' . SimWorld::STD . '/overrides', $world->writes(), 'the first run wrote the night by a group');

        self::manualChange($world, SimWorld::STD, self::GROUP_NIGHT, '15');
        $sent = count($world->writes());
        $world->later('+3 hours');
        $second = $world->run();

        self::assertSame([], self::touched($world, $sent, SimWorld::STD, self::GROUP_NIGHT), 'the night changed by hand was not written again');
        self::assertSame('15', $world->apis->world()['overrides'][SimWorld::STD][self::GROUP_NIGHT]['min_price'] ?? null);
        self::assertGreaterThan(0, $world->summary($second->runId)['watch']['nights_frozen'] ?? 0, 'the night is frozen');
    }

    public function testThePluginsOwnWritesAreNotTakenForManualChanges(): void
    {
        $world = self::world();
        $world->run();
        $world->later('+3 hours');
        $second = $world->run();

        $watch = $world->summary($second->runId)['watch'] ?? [];
        self::assertSame(0, $watch['manual_changes'] ?? null, 'a write merged into a rule with a fixed price or a fixed floor is the plugin\'s own: ' . json_encode($watch));
        self::assertSame(0, $watch['nights_frozen'] ?? null);
    }

    public function testAProcessKilledWhileWritingIsNeverContinuedFromTheSameReads(): void
    {
        $world = self::world();
        $world->apis->crashAfterWrites(6);
        $crashed = $world->run();
        self::assertSame(LaunchStatus::Failed, $crashed->status);
        // The process died before it could even record the failure: the run is still open at the
        // stage before the write, as after a real kill, and the scheduler continues it later.
        $world->db->update('runs', ['status' => 'running', 'finished_at' => null], ['id' => $crashed->runId]);
        self::manualChange($world, SimWorld::GDN, '2027-03-04', '15');
        $sent = count($world->writes());
        $world->later('+11 minutes');

        $continued = $world->stack->launcher()->continueOpen($world->property(), 300);

        self::assertSame(LaunchStatus::Failed, $continued?->status, 'the run is closed instead of writing again');
        self::assertStringContainsString('interrupted', (string) $continued?->note);
        self::assertCount($sent, $world->writes(), 'nothing was sent from the dead request\'s reads (no write, no recalculation)');

        $world->later('+2 minutes');
        $fresh = $world->run();
        self::assertSame(LaunchStatus::Completed, $fresh->status, (string) $fresh->note);
        self::assertSame([], self::touched($world, $sent, SimWorld::GDN, '2027-03-04'), 'the manual change made meanwhile is kept');
        self::assertSame('15', $world->apis->world()['overrides'][SimWorld::GDN]['2027-03-04']['min_price'] ?? null);
    }

    private static function world(): RunWorld
    {
        return new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]]);
    }

    /**
     * Someone changes the floor of a night in the pricing system's own screen.
     */
    private static function manualChange(RunWorld $world, string $listing, string $night, string $floor): void
    {
        $body = ['pms' => 'beds24', 'update_children' => false, 'overrides' => [['date' => $night, 'min_price' => $floor, 'min_price_type' => 'percent_base', 'reason' => 'by hand']]];
        $world->apis->request('POST', 'https://api.pricelabs.co/v1/listings/' . $listing . '/overrides', ['X-API-Key' => 'sim-pricelabs-key-0001'], (string) json_encode($body));
    }

    /**
     * The methods of the writes after the first $from that touched a night of a listing.
     *
     * @return list<string>
     */
    private static function touched(RunWorld $world, int $from, string $listing, string $night): array
    {
        $out = [];
        foreach (array_slice($world->apis->writeBodies(), $from) as $write) {
            foreach ((array) ($write['body']['overrides'] ?? []) as $override) {
                if (str_contains($write['path'], $listing) && ($override['date'] ?? '') === $night) {
                    $out[] = $write['method'];
                }
            }
        }

        return $out;
    }
}
