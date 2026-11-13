<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * The security watch: bookings created or changed by an origin outside the known list, bookings
 * without value. Cancellation bursts follow the waves section (Domain\Write\Hold\WaveConfig).
 */
final class SecurityConfig
{
    /** @var array<string, true> lower-cased known origins */
    private readonly array $known;

    /**
     * @param list<string> $knownOrigins origins that may create or change bookings (compared without case)
     * @param bool         $originsConfirmed off: the unknown-origin check is learning (it reports the origins it sees and alerts nothing)
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly array $knownOrigins = [],
        public readonly bool $originsConfirmed = false,
        public readonly bool $zeroValue = true,
    ) {
        $this->known = array_fill_keys(array_map(static fn (string $o): string => mb_strtolower(trim($o)), $knownOrigins), true);
    }

    public function isKnown(string $origin): bool
    {
        return isset($this->known[mb_strtolower(trim($origin))]);
    }
}
