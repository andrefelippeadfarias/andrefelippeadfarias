<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Closure;
use DateTimeImmutable;
use Ritmo\Application\Write\Gate\IssuedToken;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Plan\ValidatedPlan;

/**
 * One write run to execute: the property, the validated plan the token was issued for, the
 * token with its steps, the connectors, the lock heartbeat and the time left.
 */
final class WriteRun
{
    /**
     * @param Closure(): bool         $heartbeat keeps the site's write lock; false when it was lost
     * @param DateTimeImmutable|null  $deadline  when the process must be done (max execution time); null: no limit
     * @param DateTimeImmutable|null  $runStart  start of the run for the recalculation quota's "same run" rule
     */
    public function __construct(
        public readonly int $runId,
        public readonly Property $property,
        public readonly ValidatedPlan $plan,
        public readonly IssuedToken $issued,
        public readonly PricingConnector $pricing,
        public readonly ?PmsConnector $pms,
        public readonly Closure $heartbeat,
        public readonly ?DateTimeImmutable $deadline = null,
        public readonly ?DateTimeImmutable $runStart = null,
    ) {
    }
}
