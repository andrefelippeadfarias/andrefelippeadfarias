<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Schedule;

use DateTimeImmutable;
use Ritmo\Application\Write\WriteConfig;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Plan\Payload\CallKind;
use Ritmo\Domain\Plan\Payload\ListingWrite;
use Ritmo\Domain\Plan\Payload\OverrideWrite;
use Ritmo\Domain\Plan\Payload\RoomCall;
use Ritmo\Domain\Plan\Payload\Slice;
use Ritmo\Domain\Support\Days;

/**
 * Turns the slices of the payload (M2 Slicer: deletions first, tonight, tomorrow, the rest, the
 * listing changes) and the PMS changes into the ordered steps of a write run:
 *
 * 1. recalculations asked before writing (a listing not priced today);
 * 2. every deletion, each followed RIGHT AFTER by the rewrite of the same nights carved out of the
 *    writes: the two share a group key (a night must not stay without its rule between them);
 * 3. the other writes in the Slicer's order (tonight, tomorrow, the rest), with the dates at the
 *    edge of the pricing horizon moved to calls of their own (one refused far date must not take
 *    the others with it);
 * 4. the PMS changes in blocks of at most the configured ranges, in the batch's order (urgent
 *    nights first);
 * 5. the listing changes last.
 *
 * Pure: nothing is sent. The Slicer itself is not changed.
 */
final class WriteSchedule
{
    public function __construct(private readonly WriteConfig $config = new WriteConfig())
    {
    }

    /**
     * @param list<Slice>          $slices      the Slicer's output for the validated plan
     * @param list<CalendarChange> $pms         the PMS changes, in the batch's order
     * @param list<string>         $recalculate listing ids to recalculate before their writes
     *
     * @return list<WriteStep>
     */
    public function build(array $slices, DateTimeImmutable $today, array $pms = [], array $recalculate = []): array
    {
        $writes = array_values(array_filter($slices, static fn (Slice $s): bool => $s->kind === CallKind::Write));
        $rooms = [];
        foreach ($slices as $slice) {
            if ($slice->request instanceof RoomCall || $slice->request instanceof ListingWrite) {
                $rooms[$slice->request->listingRef] = $slice->roomCode();
            }
        }

        $steps = [];
        foreach (array_values(array_unique($recalculate)) as $listing) {
            $steps[] = new WriteStep(0, WriteOperation::Recalculation, $rooms[$listing] ?? '', [$listing => []], [], 'recalculation');
        }
        foreach ($slices as $slice) {
            if ($slice->kind === CallKind::Delete && $slice->request instanceof RoomCall) {
                array_push($steps, ...$this->group($slice->request, $writes));
            }
        }
        foreach ($writes as $slice) {
            array_push($steps, ...$this->writeSteps($slice, $today));
        }
        array_push($steps, ...$this->pmsSteps($pms));
        foreach ($slices as $slice) {
            if ($slice->request instanceof ListingWrite) {
                $steps[] = new WriteStep(0, WriteOperation::ListingUpdate, $slice->roomCode(), [$slice->request->listingRef => []], $slice->request->fields, Slice::LISTING);
            }
        }

        return array_map(static fn (WriteStep $step, int $i): WriteStep => $step->at($i + 1), $steps, array_keys($steps));
    }

    /**
     * The deletion and, right after it, the rewrite of the same nights taken out of the writes.
     *
     * @param list<Slice> $writes the write slices; the carved entries are removed from them
     *
     * @return list<WriteStep>
     */
    private function group(RoomCall $deletion, array &$writes): array
    {
        $dates = array_map(static fn (DateTimeImmutable $d): string => Days::key($d), $deletion->entries);
        $rewrite = [];
        foreach ($writes as $i => $slice) {
            $call = $slice->request;
            if (!$call instanceof RoomCall || $call->listingRef !== $deletion->listingRef) {
                continue;
            }
            $kept = [];
            foreach ($call->entries as $entry) {
                if ($entry instanceof OverrideWrite && in_array(Days::key($entry->date), $dates, true)) {
                    $rewrite[] = $entry;
                } else {
                    $kept[] = $entry;
                }
            }
            $writes[$i] = new Slice($slice->number, $slice->kind, $slice->label, $call->with($kept));
        }
        usort($rewrite, static fn (OverrideWrite $a, OverrideWrite $b): int => $a->date <=> $b->date);
        $key = $rewrite === [] ? '' : 'g' . substr(hash('sha256', $deletion->listingRef . '|' . implode(',', $dates)), 0, 15);
        $steps = [new WriteStep(0, WriteOperation::OverridesDelete, $deletion->roomCode, [$deletion->listingRef => $dates], $dates, 'delete', $key)];
        if ($rewrite !== []) {
            $steps[] = self::overrides($deletion, $rewrite, 'rewrite', $key);
        }

        return $steps;
    }

    /**
     * @return list<WriteStep> the slice's writes, with the horizon edge in a call of its own
     */
    private function writeSteps(Slice $slice, DateTimeImmutable $today): array
    {
        $call = $slice->request;
        if (!$call instanceof RoomCall || $call->entries === []) {
            return [];
        }
        $edge = $this->config->horizonEdgeDays > 0 ? Days::add($today, $this->config->horizonDays - $this->config->horizonEdgeDays) : null;
        $main = $far = [];
        foreach ($call->entries as $entry) {
            if ($edge !== null && $call->dateOf($entry) >= $edge) {
                $far[] = $entry;
            } else {
                $main[] = $entry;
            }
        }
        $steps = [];
        if ($main !== []) {
            $steps[] = self::overrides($call, $main, $slice->label, '');
        }
        if ($far !== []) {
            $steps[] = self::overrides($call, $far, 'edge', '');
        }

        return $steps;
    }

    /**
     * @param list<CalendarChange> $changes
     *
     * @return list<WriteStep>
     */
    private function pmsSteps(array $changes): array
    {
        $blocks = array_chunk($changes, $this->config->pmsMaxRangesPerPost);
        $steps = [];
        foreach ($blocks as $k => $block) {
            $nights = [];
            foreach ($block as $change) {
                $nights[$change->roomId] = [...($nights[$change->roomId] ?? []), ...$change->nights()];
            }
            $codes = array_values(array_unique(array_map(static fn (CalendarChange $c): string => $c->roomCode, $block)));
            $steps[] = new WriteStep(
                0,
                WriteOperation::PmsCalendar,
                implode(',', $codes),
                $nights,
                array_map(static fn (CalendarChange $c): array => $c->range(), $block),
                sprintf('pms %d/%d', $k + 1, count($blocks)),
                '',
                $block,
            );
        }

        return $steps;
    }

    /**
     * @param list<OverrideWrite|DateTimeImmutable> $entries
     */
    private static function overrides(RoomCall $call, array $entries, string $label, string $groupKey): WriteStep
    {
        $rules = array_values(array_filter($entries, static fn ($e): bool => $e instanceof OverrideWrite));

        return new WriteStep(
            0,
            WriteOperation::OverridesWrite,
            $call->roomCode,
            [$call->listingRef => array_map(static fn (OverrideWrite $o): string => Days::key($o->date), $rules)],
            array_map(static fn (OverrideWrite $o): array => $o->toArray(), $rules),
            $label,
            $groupKey,
        );
    }
}
