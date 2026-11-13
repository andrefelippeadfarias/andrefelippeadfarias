<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Market\MarketFreshness;
use Ritmo\Application\Market\MarketReminder;
use Ritmo\Application\Report\Store\SnapshotStore;
use Ritmo\Application\Run\RunEndChecks;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * The checks at the end of a full run (M3 pendency D5, stay spec 3.5): steps not confirmed at the
 * channel raise one delivery_overdue per property, never while the pricing system's own push is
 * known to be off, and resolve once none is late; a run whose PMS changes hit the cap raises
 * pms_changes_capped.
 */
final class RunEndChecksTest extends TestCase
{
    private RunWorld $world;

    protected function setUp(): void
    {
        $this->world = new RunWorld();
    }

    public function testOverdueStepsRaiseOneAlertThatResolvesWhenNoneIsLate(): void
    {
        $this->check(['verify' => ['delivery' => ['overdue' => 2]]]);
        $this->check(['verify' => ['delivery' => ['overdue' => 3]]]);
        self::assertCount(1, $this->active(AlertCode::DeliveryOverdue));

        $this->check(['verify' => ['delivery' => ['overdue' => 0]]]);
        self::assertSame([], $this->active(AlertCode::DeliveryOverdue));
    }

    public function testNoOverdueAlertWhileThePushIsKnownToBeOff(): void
    {
        $this->world->stack->alerts()->raiseAll([new AlertDraft(AlertCode::PushDisabled, RunWorld::PROPERTY, ['listing' => 'STD'], 'Push off.')]);

        $this->check(['verify' => ['delivery' => ['overdue' => 2]]]);

        self::assertSame([], $this->active(AlertCode::DeliveryOverdue));
    }

    public function testARunThatHitTheCapOfPmsChangesRaisesItsAlert(): void
    {
        $this->check(['decide' => ['pms_capped' => true]]);
        self::assertCount(1, $this->active(AlertCode::PmsChangesCapped));

        $this->check(['decide' => ['pms_capped' => false]]);
        self::assertSame([], $this->active(AlertCode::PmsChangesCapped));
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function check(array $summary): void
    {
        $db = $this->world->db;
        $checks = new RunEndChecks(
            new MarketReminder(new MarketFreshness(new StaySampleRepository($db)), $this->world->stack->marketRuler()),
            new SnapshotStore($db),
            $this->world->stack->alerts(),
            new AlertLedger($db),
        );
        $today = $this->world->clock->today('UTC');
        $context = new RunContext(1, $this->world->property(), [], RuleSet::defaults(), $today, DateRange::nights($today, 30), 'report', $summary, [], 'full');
        $checks->check($context, (array) ($summary['verify'] ?? []));
    }

    /**
     * @return list<mixed>
     */
    private function active(AlertCode $code): array
    {
        return (new AlertLedger($this->world->db))->active(RunWorld::PROPERTY, [$code->value]);
    }
}
