<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * The run pipeline against the simulator in each mode: shadow writes nothing; approval writes
 * nothing before a person approves and the approved entries after; autonomous writes in slices.
 */
final class ModesEndToEndTest extends TestCase
{
    public function testAShadowRunDecidesEverythingAndSendsNoWrite(): void
    {
        $world = new RunWorld();

        $result = $world->run();

        self::assertSame(LaunchStatus::Completed, $result->status, $result->note);
        $summary = $world->summary($result->runId);
        self::assertGreaterThan(0, $summary['validate']['would_write'] ?? 0, 'the plan has something to write');
        self::assertSame(0, $summary['write']['writes_sent'] ?? null);
        self::assertSame([], $world->writes(), 'no write request reached the simulator');
        self::assertNotSame([], $summary['report']['items'] ?? []);
    }

    public function testAnAutonomousRunWritesItsPlanInSlicesAndRecordsWhatWasStored(): void
    {
        $world = new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]]);

        $result = $world->run();

        self::assertSame(LaunchStatus::Completed, $result->status, $result->note);
        $summary = $world->summary($result->runId);
        self::assertGreaterThan(0, $summary['write']['writes_sent'] ?? 0, json_encode($summary['write'] ?? []));
        self::assertNotSame([], $world->writes());
        self::assertGreaterThan(0, $summary['verify']['nights_confirmed'] ?? 0);
        self::assertGreaterThan(0, $summary['verify']['recorded']['changes'] ?? 0);
    }
}
