<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\AfterWriteStage;
use Ritmo\Application\ShadowRun\StageResult;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;

/**
 * The report of the run, kept with it (operations spec 9, O44-O46): the manual sync of tonight and
 * tomorrow first when the run wrote them, then security and overbooking, then where every item
 * went (written and confirmed, not confirmed, held by a cancellation wave, waiting for approval,
 * refused, or what shadow would write). A light run without anything to do says so in three
 * lines. No guest data: bookings only by count.
 */
final class ReportStage implements AfterWriteStage
{
    public const NAME = 'report';

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $summary = $context->summary;
        $watch = (array) ($summary['watch'] ?? []);
        $validate = (array) ($summary['validate'] ?? []);
        $write = (array) ($summary['write'] ?? []);
        $verify = (array) ($summary['verify'] ?? []);
        if ($context->kind === RunKind::Light->value && (int) ($watch['light']['nights'] ?? 0) === 0) {
            return new StageResult(['lines' => ['Light run: no booking moved, no sold-out night flipped, nothing changed by hand.', 'Nothing was decided.', 'Writes sent: 0.']], []);
        }
        $lines = [];
        if (($write['sync_now'] ?? false) === true) {
            $lines[] = 'Sync now: tonight or tomorrow changed; ask the pricing system to send them to the PMS at once.';
        }
        if (($watch['security_findings'] ?? 0) > 0) {
            $lines[] = sprintf('Security: %d finding(s) on the bookings; see the alerts.', $watch['security_findings']);
        }
        if (($watch['overbooked'] ?? 0) > 0) {
            $lines[] = sprintf('Overbooking: %d room night(s) sold beyond the units.', $watch['overbooked']);
        }
        if (($watch['wave_hold'] ?? false) === true) {
            $lines[] = 'A cancellation wave holds the price cuts until a person releases it.';
        }
        $lines = [...$lines, ...$this->destinies($context, $validate, $write, $verify)];

        return new StageResult(['lines' => $lines], []);
    }

    /**
     * @param array<string, mixed> $validate
     * @param array<string, mixed> $write
     * @param array<string, mixed> $verify
     *
     * @return list<string>
     */
    private function destinies(RunContext $context, array $validate, array $write, array $verify): array
    {
        $lines = [];
        $would = (int) ($validate[PlanItemStatus::WOULD_WRITE] ?? 0);
        if ($context->mode === PropertyMode::Shadow) {
            $lines[] = sprintf('Shadow: %d item(s) would be written; writes sent: 0.', $would);
        } elseif (($write['proposed'] ?? false) === true) {
            $lines[] = sprintf('Waiting for approval: %d room night(s); nothing is written before a person approves.', (int) ($write['entries'] ?? 0));
        } elseif (isset($write['refused'])) {
            $lines[] = sprintf('Not written: the write gate refused the plan (%s).', $write['refused']);
        } else {
            $confirmed = (int) ($verify['nights_confirmed'] ?? 0);
            $lines[] = sprintf('Written: %d call(s) sent, %d room night(s) confirmed stored.', (int) ($write['writes_sent'] ?? 0), $confirmed);
            if (isset($write['stop']) && $write['stop'] !== null) {
                $lines[] = sprintf('The write stopped (%s): the next run reads the systems again and sends only what is missing.', $write['stop']);
            }
        }
        if ((int) ($write['held'] ?? 0) > 0) {
            $lines[] = sprintf('Held by a cancellation wave: %d entr(ies).', (int) $write['held']);
        }
        if ((int) ($validate[PlanItemStatus::REJECTED] ?? 0) > 0) {
            $lines[] = sprintf('Refused by the validator: %d item(s).', (int) $validate[PlanItemStatus::REJECTED]);
        }
        $overdue = (int) ($verify['delivery']['overdue'] ?? 0);
        if ($overdue > 0) {
            $lines[] = sprintf('Not confirmed at the channel yet: %d step(s) overdue.', $overdue);
        }

        return $lines;
    }
}
