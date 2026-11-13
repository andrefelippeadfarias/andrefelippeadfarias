<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use DateTimeImmutable;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteToken;

/**
 * A token the gate issued, with the steps it covers (in writing order) and the entries left out
 * without refusing the plan (a listing change without a person's approval).
 */
final class IssuedToken
{
    /**
     * @param list<WriteStep>                                $steps
     * @param list<array{step: WriteStep, reason: string}>   $dropped
     */
    public function __construct(
        public readonly WriteToken $token,
        public readonly array $steps,
        public readonly array $dropped,
        public readonly string $planHash,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $actor,
    ) {
    }

    /**
     * The token id as kept in the journal: a hash, never the id itself.
     */
    public function journalId(): string
    {
        return substr(hash('sha256', $this->token->id), 0, 32);
    }
}
