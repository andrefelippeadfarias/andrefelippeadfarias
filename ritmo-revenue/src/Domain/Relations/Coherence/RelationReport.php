<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;

/**
 * What the coherence stage did: one decision per relation and night it ran, and the warnings.
 */
final class RelationReport
{
    /** @var list<RelationDecision> */
    private array $decisions = [];

    /** @var list<RelationWarning> */
    private array $warnings = [];

    public function record(RelationDecision $decision): void
    {
        $this->decisions[] = $decision;
    }

    public function warn(RelationWarning $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * @return list<RelationDecision>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /**
     * @return list<RelationWarning>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function outcomeOf(string $relationId, DateTimeImmutable $night): ?Outcome
    {
        foreach ($this->decisions as $decision) {
            if ($decision->relationId === $relationId && $decision->night == $night) {
                return $decision->outcome;
            }
        }

        return null;
    }

    /**
     * The id of the occupancy switch that holds the room-night without a price item because the
     * target is already where the switch puts it, or null. The validator accepts that night.
     */
    public function satisfiedBySwitch(string $room, DateTimeImmutable $night): ?string
    {
        foreach ($this->decisions as $decision) {
            if ($decision->target === $room && $decision->night == $night && $decision->outcome === Outcome::SwitchSatisfied) {
                return $decision->relationId;
            }
        }

        return null;
    }
}
