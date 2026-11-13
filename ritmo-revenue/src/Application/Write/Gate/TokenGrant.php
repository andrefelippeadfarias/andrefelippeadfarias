<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use DateTimeImmutable;
use Ritmo\Contracts\WriteRequest;

/**
 * What one issued token may do, kept by the gate's live registry (never by the token): the
 * steps it was issued for (each usable once), until when, for which property day, and whether it
 * was revoked.
 */
final class TokenGrant
{
    /** @var array<int, true> positions of the steps already used */
    private array $used = [];

    private bool $revoked = false;

    /**
     * @param list<WriteRequest> $steps
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly string $timezone,
        public readonly string $today,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $steps,
    ) {
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    /**
     * Uses up the first unused step that covers the request.
     *
     * @return bool false when no unused step covers it
     */
    public function use(WriteRequest $request): bool
    {
        foreach ($this->steps as $index => $step) {
            if (!isset($this->used[$index]) && self::covers($step, $request)) {
                $this->used[$index] = true;

                return true;
            }
        }

        return false;
    }

    private static function covers(WriteRequest $step, WriteRequest $request): bool
    {
        if ($step->operation !== $request->operation || array_diff($request->fields, $step->fields) !== []) {
            return false;
        }
        foreach ($request->nights as $target => $nights) {
            if (!isset($step->nights[$target]) || array_diff($nights, $step->nights[$target]) !== []) {
                return false;
            }
        }

        return true;
    }
}
