<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\NightFields;

/**
 * How many times the pricing system's push put back a PMS value the plugin had written, per room
 * night and field, since the stay configuration last changed (spec 06, 3.5 pms_reverted_by_push;
 * divergence SW-X3). Immutable: each observation gives a new ledger; the integration stores it.
 */
final class RevertLedger
{
    /**
     * @param array<string, int> $counts "room|night|field" => reverts seen
     */
    public function __construct(private readonly array $counts = [])
    {
    }

    /**
     * The ledger after a run observed a room night the plugin had written: every field whose value
     * now equals what the push sets, and no longer what was written, counts one revert.
     */
    public function observe(string $room, DateTimeImmutable $night, NightFields $written, NightFields $observed, NightFields $pushed): self
    {
        $counts = $this->counts;
        foreach ($written->all() as $field => $value) {
            if (!$pushed->has($field) || !$observed->has($field)) {
                continue;
            }
            $now = $observed->get($field);
            if (NightFields::same($now, $pushed->get($field)) && !NightFields::same($now, $value)) {
                $key = self::key($room, $night, $field);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return new self($counts);
    }

    public function count(string $room, DateTimeImmutable $night, string $field): int
    {
        return $this->counts[self::key($room, $night, $field)] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->counts;
    }

    private static function key(string $room, DateTimeImmutable $night, string $field): string
    {
        return $room . '|' . Days::key($night) . '|' . $field;
    }
}
