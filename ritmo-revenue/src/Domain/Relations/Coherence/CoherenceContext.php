<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\BlockedNights;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Port\RelationHooks;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;

/**
 * Everything the relation rules of one coherence pass share.
 */
final class CoherenceContext
{
    public function __construct(
        public readonly RelationGraph $graph,
        public readonly RelationSettings $settings,
        public readonly NightFacts $facts,
        public readonly RelationHooks $hooks,
        public readonly PlanDraft $draft,
        public readonly RelationReport $report,
        public readonly DateTimeImmutable $today,
    ) {
    }

    /**
     * Free for the chain relations (at least, between, premium): with "ignore" a room closed in the
     * PMS still counts; with "exclude" it does not.
     */
    public function chainFree(string $room, DateTimeImmutable $night): bool
    {
        return $this->settings->blockedNights === BlockedNights::Ignore
            ? $this->facts->isFree($room, $night)
            : $this->facts->isFreeUnblocked($room, $night);
    }

    public function nightType(DateTimeImmutable $night): NightType
    {
        return $this->hooks->calendar->nightType($night);
    }

    /**
     * @param array<string, scalar|null> $args
     */
    public function warn(string $code, Relation $relation, DateTimeImmutable $night, array $args = []): void
    {
        $this->report->warn(new RelationWarning($code, $relation->id, $relation->target, $night, $args));
    }
}
