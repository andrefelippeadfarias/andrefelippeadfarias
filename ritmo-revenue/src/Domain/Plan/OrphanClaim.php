<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Ritmo\Domain\Calendar\Stay\OrphanProof;

/**
 * What an item claims when it gives a night a shorter stay, or opens a closed day, because the night
 * is an orphan: which kind of orphan, and the occupancy read that proves it (checked by the
 * validator before anything is written).
 *
 * Reference: the "orfa" and "orfa_prova" fields of an item of validar_plano.py.
 */
final class OrphanClaim
{
    public function __construct(
        public readonly OrphanClaimKind $kind,
        public readonly ?OrphanProof $proof = null,
    ) {
    }

    /**
     * @return array{kind: string, proof: array<string, int|string>|null}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'proof' => $this->proof?->toArray()];
    }
}
