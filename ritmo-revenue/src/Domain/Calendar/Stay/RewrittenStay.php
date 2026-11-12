<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * The stay and departure mask a rewritten date rule carries, and the orphan behind a one-night
 * stay with its proof.
 */
final class RewrittenStay
{
    public function __construct(
        public readonly ?int $minStay,
        public readonly ?string $checkoutMask,
        public readonly ?OrphanKind $orphan = null,
        public readonly ?OrphanProof $proof = null,
    ) {
    }
}
