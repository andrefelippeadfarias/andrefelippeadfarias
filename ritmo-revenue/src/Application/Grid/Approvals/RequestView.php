<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use DateTimeImmutable;

/**
 * One request as the approval screen shows it: its facts, its night entries grouped into
 * segments per room, its listing changes apart, the impact and the relation pairs.
 */
final class RequestView
{
    /**
     * @param array<string, list<Segment>>                                         $rooms   room code => segments, in room order
     * @param list<EntryRow>                                                       $listing listing changes
     * @param list<array{relation: string, night: string, rooms: list<string>}>    $pairs
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $status,
        public readonly DateTimeImmutable $planDay,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $expiryReason,
        public readonly bool $expired,
        public readonly ?int $runId,
        public readonly ?int $writeRunId,
        public readonly ?int $decidedBy,
        public readonly ?DateTimeImmutable $decidedAt,
        public readonly string $note,
        public readonly int $entries,
        public readonly array $rooms,
        public readonly array $listing,
        public readonly Impact $impact,
        public readonly array $pairs,
    ) {
    }

    public function segmentCount(): int
    {
        return array_sum(array_map('count', $this->rooms));
    }
}
