<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Support;

use InvalidArgumentException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\QuotaLedger;
use Ritmo\Domain\Support\Clock;

/**
 * A limit of calls per rolling window that holds across runs and PHP processes, because every
 * call is written to the quota ledger.
 *
 * It refuses, it does not wait: when the window is full the connector stops with RateLimited
 * before anything is sent. Pacing calls inside one process (waiting a few seconds for a
 * per-minute limit) is the job of the HTTP client the connector is given.
 */
final class RequestBudget
{
    /** The ledger action under which every request of a connector is counted. */
    public const ACTION = 'request';

    /** The whole account, in the ledger's vocabulary. */
    private const ACCOUNT = '';

    /**
     * @param string $provider      connector id, as recorded in the ledger
     * @param string $label         name of the system as shown to a person
     * @param int    $maxCalls      calls allowed inside the window
     * @param int    $windowSeconds length of the rolling window
     *
     * @throws InvalidArgumentException on a limit or window below one
     */
    public function __construct(
        private readonly QuotaLedger $ledger,
        private readonly Clock $clock,
        private readonly string $provider,
        private readonly string $label,
        private readonly int $maxCalls,
        private readonly int $windowSeconds,
    ) {
        if ($maxCalls < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('A request budget needs a positive limit and window.');
        }
    }

    /**
     * @throws RateLimited when the window already holds as many calls as it allows
     */
    public function assertOpen(): void
    {
        $now = $this->clock->now();
        $since = $now->setTimestamp($now->getTimestamp() - $this->windowSeconds);
        $used = $this->ledger->countSince($this->provider, self::ACTION, self::ACCOUNT, $since);
        if ($used < $this->maxCalls) {
            return;
        }

        throw new RateLimited(
            sprintf(
                '%s request limit reached: %d calls in the last %d seconds (limit %d); no request was sent.',
                $this->label,
                $used,
                $this->windowSeconds,
                $this->maxCalls
            ),
            $this->provider,
            $this->windowSeconds,
            null
        );
    }

    /**
     * Counts one call that was sent, whatever its outcome.
     */
    public function record(bool $succeeded): void
    {
        $this->ledger->record($this->provider, self::ACTION, self::ACCOUNT, $succeeded, $this->clock->now());
    }
}
