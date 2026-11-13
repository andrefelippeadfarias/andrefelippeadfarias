<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\DateRange;

/**
 * Everything a stage of one run works with: the property, its mapped room types, the rules the
 * run started with, the property day, the nights it covers, the summary so far, the state the
 * previous stages left, the kind of run and the mode it runs in (fixed when it started: shadow for
 * a shadow run and for any run under the emergency switch).
 */
final class RunContext
{
    /**
     * @param list<RoomType>       $rooms   room types of the property that are mapped to the PMS
     * @param array<string, mixed> $summary summary of the stages completed so far, by stage
     * @param array<string, mixed> $state   data the completed stages carry to the next ones
     */
    public function __construct(
        public readonly int $runId,
        public readonly Property $property,
        public readonly array $rooms,
        public readonly RuleSet $rules,
        public readonly DateTimeImmutable $today,
        public readonly DateRange $horizon,
        public readonly string $stage = '',
        public readonly array $summary = [],
        public readonly array $state = [],
        public readonly string $kind = 'shadow',
        public readonly PropertyMode $mode = PropertyMode::Shadow,
        public readonly ?int $rulesetVersion = null,
        private readonly ?Closure $heartbeat = null,
    ) {
    }

    /**
     * The same context with the heartbeat of the request working on the run (it extends the
     * property's run lock and answers false when the lock was lost).
     *
     * @param (Closure(): bool)|null $heartbeat
     */
    public function withHeartbeat(?Closure $heartbeat): self
    {
        return new self($this->runId, $this->property, $this->rooms, $this->rules, $this->today, $this->horizon, $this->stage, $this->summary, $this->state, $this->kind, $this->mode, $this->rulesetVersion, $heartbeat);
    }

    /**
     * Extends the run lock; false when the run lost it. True when nothing holds a lock (tests).
     */
    public function stillHeld(): bool
    {
        return $this->heartbeat === null || ($this->heartbeat)() === true;
    }

    /**
     * The context after a stage completed.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $state
     */
    public function after(string $stage, array $summary, array $state): self
    {
        return new self($this->runId, $this->property, $this->rooms, $this->rules, $this->today, $this->horizon, $stage, $summary, $state, $this->kind, $this->mode, $this->rulesetVersion, $this->heartbeat);
    }

    /**
     * Instant of the PMS calendar read of this run: later stages read the snapshots as of then.
     */
    public function capturedAt(): ?DateTimeImmutable
    {
        return StateCodec::instant(is_string($this->state['captured_at'] ?? null) ? $this->state['captured_at'] : null);
    }

    /**
     * Listings of the pricing system, by room code, as StateCodec::listing() stores them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function listings(): array
    {
        return is_array($this->state['listings'] ?? null) ? $this->state['listings'] : [];
    }

    /**
     * Nights read from the pricing system's calendar (only when that read is allowed).
     *
     * @return array<string, array<string, PriceNight>> room code => 'YYYY-MM-DD' => night
     */
    public function pricingNights(): array
    {
        return $this->decoded('prices', StateCodec::toPriceNight(...));
    }

    /**
     * Date overrides in force in the pricing system.
     *
     * @return array<string, array<string, DateOverride>> room code => 'YYYY-MM-DD' => override
     */
    public function overrides(): array
    {
        return $this->decoded('overrides', StateCodec::toOverride(...));
    }

    public function readsPricingCalendar(): bool
    {
        return ($this->state['pricing_calendar_read'] ?? false) === true;
    }

    /**
     * @template T
     *
     * @param callable(array<string, mixed>): T $decode
     *
     * @return array<string, array<string, T>>
     */
    private function decoded(string $key, callable $decode): array
    {
        $out = [];
        foreach (is_array($this->state[$key] ?? null) ? $this->state[$key] : [] as $code => $nights) {
            foreach ((array) $nights as $night => $data) {
                $out[(string) $code][(string) $night] = $decode((array) $data);
            }
        }

        return $out;
    }
}
