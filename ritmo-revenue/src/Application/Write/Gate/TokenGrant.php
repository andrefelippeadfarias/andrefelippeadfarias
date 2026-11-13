<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use DateTimeImmutable;
use Ritmo\Contracts\WriteRequest;

/**
 * What one issued token may do, kept by the gate's live registry (never by the token): the
 * steps it was issued for (each usable once, and only with the values the gate checked), until
 * when, for which property day, and whether it was revoked. Each step a connector uses arms one
 * HTTP write, which the HTTP guard claims (claim()); the same request may go once more (a PMS
 * token renewed after a 401), any other needs another step.
 */
final class TokenGrant
{
    /** @var array<int, true> positions of the steps already used */
    private array $used = [];

    private bool $revoked = false;

    /** Steps used whose HTTP write the guard has not claimed yet. */
    private int $armed = 0;

    private ?string $lastClaim = null;

    private bool $repeated = false;

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
                ++$this->armed;

                return true;
            }
        }

        return false;
    }

    /**
     * The HTTP guard takes the write a used step armed: true for a new request when a step is
     * armed, or for the same request as the last one once more.
     *
     * @param string $signature digest of the method, the URL and the body
     */
    public function claim(string $signature): bool
    {
        if ($this->armed > 0) {
            --$this->armed;
            $this->lastClaim = $signature;
            $this->repeated = false;

            return true;
        }
        if ($this->lastClaim === $signature && !$this->repeated) {
            $this->repeated = true;

            return true;
        }

        return false;
    }

    private static function covers(WriteRequest $step, WriteRequest $request): bool
    {
        if ($step->operation !== $request->operation || array_diff($request->fields, $step->fields) !== []) {
            return false;
        }
        if ($step->entries !== [] && ($request->entries === [] || array_diff($request->entries, $step->entries) !== [])) {
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
