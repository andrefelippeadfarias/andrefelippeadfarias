<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The steps written to the pricing system, oldest first. Immutable: recording or marking returns
 * a new ledger, which the caller stores.
 *
 * The plan never records steps: only the items actually written, after the write is confirmed,
 * and only those carrying a direction (a ladder item inside the tolerance, a protection item or a
 * relation item is not a step).
 *
 * Reference: pace.ultimo_passo, plano_no_ledger, registrar_passos, marcar_envio.
 */
final class StepLedger
{
    /**
     * @param list<StepRecord> $records in the order they were recorded
     */
    public function __construct(private readonly array $records = [])
    {
    }

    /**
     * @return list<StepRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * The step with the latest write instant for the room night; on a tie, the first recorded.
     */
    public function lastStep(string $room, DateTimeImmutable $night): ?StepRecord
    {
        $last = null;
        foreach ($this->records as $record) {
            if ($record->isFor($room, $night) && ($last === null || $record->writtenAt > $last->writtenAt)) {
                $last = $record;
            }
        }

        return $last;
    }

    /**
     * True when a ladder already stepped the room night.
     */
    public function hasLadderStep(string $room, DateTimeImmutable $night): bool
    {
        foreach ($this->records as $record) {
            if ($record->isFor($room, $night) && $record->isLadderStep()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a step of the room night (either direction) was written at or after the instant.
     */
    public function hasStepSince(string $room, DateTimeImmutable $night, DateTimeImmutable $instant): bool
    {
        foreach ($this->records as $record) {
            if ($record->isFor($room, $night) && $record->writtenAt >= $instant) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ledger with the written steps appended: one row for every proposal with a direction,
     * stamped with the write instant to the second and not yet on the channel.
     *
     * @param iterable<StepProposal> $written the steps of the items that were written
     */
    public function record(iterable $written, DateTimeImmutable $now): self
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $stamp = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
        $records = $this->records;
        foreach ($written as $step) {
            if ($step->direction === null) {
                continue;
            }
            $records[] = new StepRecord(
                $step->roomCode,
                $step->night,
                $step->priceFrom,
                $step->priceTo,
                $step->direction,
                $step->sourceStatus,
                $step->sold,
                $step->soldBase,
                $step->soldTotal,
                $stamp,
            );
        }

        return new self($records);
    }

    /**
     * The ledger with every step not yet on the channel and written no later than the push
     * marked as arrived. $pushedAt is one instant for every room, or one per room code (rooms
     * missing from the map are left as they are). A step stored to the minute arrives only when
     * its whole minute ended before the push.
     *
     * @param DateTimeImmutable|array<string, DateTimeImmutable> $pushedAt
     */
    public function markOnChannel(DateTimeImmutable|array $pushedAt): self
    {
        $records = [];
        foreach ($this->records as $record) {
            $push = is_array($pushedAt) ? ($pushedAt[$record->roomCode] ?? null) : $pushedAt;
            $records[] = $push !== null && $record->onChannelAt === null && $record->writtenAtLatest() <= $push
                ? $record->onChannel($push)
                : $record;
        }

        return new self($records);
    }

    /**
     * Number of steps not yet on the channel.
     */
    public function pendingCount(): int
    {
        return count(array_filter($this->records, static fn (StepRecord $r): bool => $r->onChannelAt === null));
    }
}
