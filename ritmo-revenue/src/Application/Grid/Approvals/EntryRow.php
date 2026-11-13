<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use DateTimeImmutable;

/**
 * One entry of a request as the approval screen shows it: what is sent, before and after in
 * money (from the plan items of the run that proposed it), the reason and the warnings. Plain
 * values; the screen turns them into words. beforeFromListing: the night had no date rule, so the
 * amounts before are the listing's minimum and maximum.
 */
final class EntryRow
{
    public const UP = 'up';
    public const DOWN = 'down';
    public const BOUNDS = 'bounds';
    public const REWRITE = 'rewrite';
    public const LISTING = 'listing';
    public const PMS = 'pms';

    /**
     * @param array<string, int|float|string>                         $fields   what is sent
     * @param list<array{code: string, params: array<string, mixed>}> $trace
     * @param list<string>                                            $warnings codes of EntryWarnings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $room,
        public readonly ?DateTimeImmutable $night,
        public readonly string $subject,
        public readonly string $group,
        public readonly array $fields,
        public readonly ?string $orderId,
        public readonly string $direction,
        public readonly ?float $floorBefore,
        public readonly ?float $floorAfter,
        public readonly ?int $floorPercent,
        public readonly ?float $ceilingBefore,
        public readonly ?float $ceilingAfter,
        public readonly ?int $ceilingPercent,
        public readonly string $reason,
        public readonly ?string $paceStatus,
        public readonly array $trace,
        public readonly string $special,
        public readonly array $warnings,
        public readonly bool $held,
        public readonly bool $beforeFromListing = false,
    ) {
    }

    /** The amount that moves: the floor when it is sent, else the ceiling. */
    public function before(): ?float
    {
        return $this->floorAfter !== null ? $this->floorBefore : $this->ceilingBefore;
    }

    public function after(): ?float
    {
        return $this->floorAfter ?? $this->ceilingAfter;
    }

    /** The change in percent of the moving amount; null when one side is unknown. */
    public function changePercent(): ?float
    {
        $before = $this->before();
        $after = $this->after();

        return $before === null || $after === null || abs($before) < 0.0000001 ? null : ($after - $before) / $before * 100;
    }
}
