<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Ledger;

use DateTimeImmutable;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Pricing\Cadence\StepRecord;

/**
 * The step-ledger rows of a write: one per pace step whose night was confirmed STORED by the
 * read-back, stamped with the instant of that read-back. A step whose write was refused, left
 * unknown, held, skipped or read back different is never recorded, so the cadence (which reads
 * the ledger) never counts a step that is not in the pricing system.
 *
 * Reference: pace.registrar_passos after the API response (the plugin waits for the read-back,
 * divergence C4 of docs/VERIFY.md).
 */
final class ConfirmedSteps
{
    private function __construct()
    {
    }

    /**
     * @param list<StepProposal>  $proposals the pace steps of the plan's items
     * @param array<string, bool> $stored    "ROOM|YYYY-MM-DD" => true for the nights confirmed stored
     *
     * @return list<StepRecord>
     */
    public static function records(array $proposals, array $stored, DateTimeImmutable $storedAt): array
    {
        $confirmed = array_filter($proposals, static fn (StepProposal $p): bool => ($stored[PlanItem::keyOf($p->roomCode, $p->night)] ?? false) === true);

        return (new StepLedger())->record($confirmed, $storedAt)->records();
    }
}
