<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

/**
 * Why a relation touched an item: a code with arguments, rendered by the admin layer.
 */
final class Reason
{
    public const AT_LEAST = 'relation.at_least';
    public const BETWEEN = 'relation.between';
    public const PREMIUM_OVER_MAX = 'relation.premium_over_max';
    public const DISCOUNT_BAND = 'relation.discount_band';
    public const OCCUPANCY_SWITCH = 'relation.occupancy_switch';
    public const LISTING_CAP = 'relation.listing_cap';
    public const AUDIT_BETWEEN = 'relation.audit.between';
    public const AUDIT_PREMIUM = 'relation.audit.premium';
    public const AUDIT_BAND_CEILING = 'relation.audit.band_ceiling';

    /**
     * @param array<string, scalar|null> $args
     */
    public function __construct(
        public readonly string $code,
        public readonly ?string $relationId = null,
        public readonly array $args = [],
    ) {
    }

    public function sameAs(self $other): bool
    {
        return $this->code === $other->code && $this->relationId === $other->relationId;
    }
}
