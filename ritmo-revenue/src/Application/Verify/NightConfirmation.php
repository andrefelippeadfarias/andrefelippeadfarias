<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use Ritmo\Domain\Write\Confirm\LiveRoute;
use Ritmo\Domain\Write\Confirm\LiveVerdict;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * The confirmation of one written night: stored (read-back), then live (recalculated calendar)
 * when it could be checked, or the route that says why not yet. Immutable.
 */
final class NightConfirmation
{
    /**
     * @param list<string> $differences the fields that differ from what the write should have left
     */
    public function __construct(
        public readonly WrittenNight $written,
        public readonly StoredStatus $stored,
        public readonly NightFields $observed,
        public readonly array $differences = [],
        public readonly ?LiveRoute $liveRoute = null,
        public readonly ?LiveVerdict $live = null,
    ) {
    }

    public function withLive(LiveRoute $route, ?LiveVerdict $verdict): self
    {
        return new self($this->written, $this->stored, $this->observed, $this->differences, $route, $verdict);
    }

    public function isStored(): bool
    {
        return $this->stored === StoredStatus::Stored;
    }
}
