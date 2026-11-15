<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

use Closure;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiReply;
use Ritmo\Domain\Ai\MonthlyUsage;
use Ritmo\Domain\Ai\PriceTable;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * The usage of the assistant per calendar month of the site: every call's tokens and estimated
 * cost (never what was asked or answered). The month before is kept as totals for the screen.
 *
 * Every change is a read and a write of one stored value, done while holding the lock the site
 * gives ($exclusive), so calls at the same time never erase each other's usage. Before a call its
 * highest cost is reserved against the cap (reserve), and the answer or the failure frees it.
 */
final class UsageLedger
{
    public const KEY = 'ai_usage';

    public const PREVIOUS_KEY = 'ai_usage_previous';

    /** A reservation older than this belongs to a call that died; it no longer counts. */
    public const RESERVATION_MINUTES = 15;

    /**
     * @param (Closure(Closure(): mixed): mixed)|null $exclusive runs the work while holding the
     *                                                            site's lock of the usage; null runs it as is
     */
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
        private readonly string $timezone,
        private readonly ?Closure $exclusive = null,
    ) {
    }

    public function month(): string
    {
        return substr(Days::key(Days::localDate($this->clock->now(), $this->timezone)), 0, 7);
    }

    public function current(): MonthlyUsage
    {
        $stored = $this->store->getFresh(self::KEY);

        return MonthlyUsage::fromArray($this->month(), is_array($stored) ? $stored : []);
    }

    /**
     * The totals of the month before, when they were kept.
     *
     * @return array<string, mixed>|null
     */
    public function previous(): ?array
    {
        $stored = $this->store->get(self::PREVIOUS_KEY);

        return is_array($stored) && is_string($stored['month'] ?? null) ? $stored : null;
    }

    /**
     * Reserves the highest cost of a call; null when the month's spend, the calls under way and
     * this one would pass the cap.
     */
    public function reserve(float $usd, float $capUsd): ?string
    {
        return $this->locked(function () use ($usd, $capUsd): ?string {
            $now = $this->clock->now();
            $staleBefore = self::at($now->modify('-' . self::RESERVATION_MINUTES . ' minutes'));
            $usage = $this->fresh()->withoutStale($staleBefore);
            if ($usage->spentUsd() + $usage->reservedUsd() + $usd > $capUsd) {
                return null;
            }
            $id = bin2hex(random_bytes(8));
            $this->store->set(self::KEY, $usage->reserving($id, $usd, self::at($now), $staleBefore)->toArray());

            return $id;
        });
    }

    public function release(string $reservation): void
    {
        $this->locked(function () use ($reservation): void {
            $this->store->set(self::KEY, $this->fresh()->releasing($reservation)->toArray());
        });
    }

    public function record(AiPurpose $purpose, AiReply $reply, PriceTable $prices, ?string $reservation = null): MonthlyUsage
    {
        return $this->locked(function () use ($purpose, $reply, $prices, $reservation): MonthlyUsage {
            $usage = $this->fresh()->with(self::at($this->clock->now()), $purpose, $reply, $prices);
            if ($reservation !== null) {
                $usage = $usage->releasing($reservation);
            }
            $this->store->set(self::KEY, $usage->toArray());

            return $usage;
        });
    }

    /**
     * The month's usage as stored now; a stored month that is over is kept as the month before.
     */
    private function fresh(): MonthlyUsage
    {
        $stored = $this->store->getFresh(self::KEY);
        $month = $this->month();
        if (is_array($stored) && is_string($stored['month'] ?? null) && $stored['month'] !== $month) {
            $this->store->set(self::PREVIOUS_KEY, ['month' => $stored['month'], 'totals' => $stored['totals'] ?? []]);
            $this->store->set(self::KEY, ['month' => $month, 'calls' => [], 'totals' => []]);
        }

        return MonthlyUsage::fromArray($month, is_array($stored) ? $stored : []);
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        return $this->exclusive === null ? $work() : ($this->exclusive)($work);
    }

    private static function at(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
