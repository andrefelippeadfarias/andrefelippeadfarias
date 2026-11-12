<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * Cuts a payload into the calls the writer makes, in order: every deletion first; then tonight of
 * every listing, tomorrow of every listing, and the rest listing by listing, by date; the listing
 * changes last. A call carries at most the configured number of dates of one listing; tonight and
 * tomorrow always go in calls of their own (tonight may turn into the past while writing, and must
 * not take the rest with it).
 *
 * Reference: fatiar_payload.fatiar.
 */
final class Slicer
{
    /**
     * @throws InvalidArgumentException when a call could not carry one date
     */
    public function __construct(private readonly int $maxDates = 70)
    {
        if ($maxDates < 1) {
            throw new InvalidArgumentException('A call carries at least one date.');
        }
    }

    /**
     * @return list<Slice>
     *
     * @throws InvalidArgumentException when a call repeats a date (the pricing system merges them and the last one wins)
     */
    public function slice(Payload $payload, DateTimeImmutable $today): array
    {
        $deletions = [];
        foreach ($payload->deletions as $call) {
            foreach ($this->parts($call, $today) as $part) {
                $deletions[] = $part;
            }
        }
        $tonight = $tomorrow = $rest = [];
        foreach ($payload->writes as $call) {
            foreach ($this->parts($call, $today) as $part) {
                match ($part[0]) {
                    Slice::TONIGHT => $tonight[] = $part,
                    Slice::TOMORROW => $tomorrow[] = $part,
                    default => $rest[] = $part,
                };
            }
        }
        $slices = [];
        foreach ([...$deletions, ...$tonight, ...$tomorrow, ...$rest] as [$label, $call]) {
            $slices[] = new Slice(count($slices) + 1, $call->kind, $label, $call);
        }
        foreach ($payload->listing as $write) {
            $slices[] = new Slice(count($slices) + 1, CallKind::Listing, Slice::LISTING, $write);
        }

        return $slices;
    }

    /**
     * The parts of one listing's call: tonight, tomorrow, then the rest in chunks "k/n".
     *
     * @return list<array{0: string, 1: RoomCall}>
     */
    private function parts(RoomCall $call, DateTimeImmutable $today): array
    {
        $entries = $call->entries;
        usort($entries, static fn ($a, $b): int => $call->dateOf($a) <=> $call->dateOf($b));
        $keys = array_map(static fn ($e): string => Days::key($call->dateOf($e)), $entries);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException(sprintf('The call of listing %s repeats a date: the pricing system would keep only the last one.', $call->listingRef));
        }
        $d0 = Days::key($today);
        $d1 = Days::key(Days::add($today, 1));
        $groups = [Slice::TONIGHT => [], Slice::TOMORROW => []];
        $others = [];
        foreach ($entries as $i => $entry) {
            match ($keys[$i]) {
                $d0 => $groups[Slice::TONIGHT][] = $entry,
                $d1 => $groups[Slice::TOMORROW][] = $entry,
                default => $others[] = $entry,
            };
        }
        $chunks = array_chunk($others, $this->maxDates);
        foreach ($chunks as $k => $chunk) {
            $groups[($k + 1) . '/' . count($chunks)] = $chunk;
        }
        $parts = [];
        foreach ($groups as $label => $group) {
            foreach (array_chunk($group, $this->maxDates) as $chunk) {
                $parts[] = [(string) $label, $call->with($chunk)];
            }
        }

        return $parts;
    }
}
