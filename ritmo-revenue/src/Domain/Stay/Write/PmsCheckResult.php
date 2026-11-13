<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

/**
 * The PMS changes the check accepted and the ones it refused, each with its code; whether the run
 * hit the cap (alert pms_changes_capped) and the changes refused because the push keeps putting
 * them back (alert stay_reverted_by_push).
 */
final class PmsCheckResult
{
    /**
     * @param list<PmsChangeEntry>                               $accepted
     * @param list<array{entry: PmsChangeEntry, code: string}>  $refused
     */
    public function __construct(
        public readonly array $accepted,
        public readonly array $refused,
        public readonly bool $capped,
    ) {
    }

    /**
     * @return list<PmsChangeEntry>
     */
    public function refusedWith(string $code): array
    {
        return array_values(array_map(static fn (array $r): PmsChangeEntry => $r['entry'], array_filter($this->refused, static fn (array $r): bool => $r['code'] === $code)));
    }
}
