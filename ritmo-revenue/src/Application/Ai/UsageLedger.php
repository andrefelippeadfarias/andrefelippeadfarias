<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

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
 */
final class UsageLedger
{
    public const KEY = 'ai_usage';

    public const PREVIOUS_KEY = 'ai_usage_previous';

    public function __construct(private readonly KeyValueStore $store, private readonly Clock $clock, private readonly string $timezone)
    {
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

    public function record(AiPurpose $purpose, AiReply $reply, PriceTable $prices): MonthlyUsage
    {
        $stored = $this->store->getFresh(self::KEY);
        $month = $this->month();
        if (is_array($stored) && is_string($stored['month'] ?? null) && $stored['month'] !== $month) {
            $this->store->set(self::PREVIOUS_KEY, ['month' => $stored['month'], 'totals' => $stored['totals'] ?? []]);
        }
        $usage = MonthlyUsage::fromArray($month, is_array($stored) ? $stored : [])->with($this->clock->now()->format('Y-m-d\TH:i:s\Z'), $purpose, $reply, $prices);
        $this->store->set(self::KEY, $usage->toArray());

        return $usage;
    }
}
