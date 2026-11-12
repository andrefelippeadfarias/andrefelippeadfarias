<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * The single PMS batch of a run: every change of the audit once, the urgent ones (today to
 * today + urgentDays) first by night, property and room, then the rest by property, room and
 * night; changes of the same room and night keep the audit's order (restriction, arrival-rule
 * stay, today's closed-day orphan, package stay). Cut into blocks of at most maxRanges ranges
 * for the writer, which re-reads each block (milestone M3). Nothing here sends anything.
 *
 * Stay-only mode keeps the arrival-rule stays and today's closed-day orphan stays.
 *
 * Reference: calendario_12m.lote_beds24, lote_estadia_dia_chegada, lote_sabado_orfao_hoje,
 * plano_beds24_sabados, lote_unico; beds24.editar_lote (blocks).
 */
final class StayBatch
{
    /**
     * @param array<string, string> $propertyOfRoom room code => property id
     */
    public function __construct(
        private readonly BatchPolicy $policy,
        private readonly array $propertyOfRoom,
    ) {
    }

    /**
     * @param list<RoomAudit> $audit
     *
     * @return list<StayChange|ArrivalDepartureChange>
     */
    public function single(array $audit, DateTimeImmutable $today, bool $stayOnly): array
    {
        $changes = $this->unique($stayOnly ? $this->stayChanges($audit) : [
            ...array_merge(...array_map(static fn (RoomAudit $r): array => $r->arrivalChanges, $audit)),
            ...$this->stayChanges($audit),
            ...array_merge(...array_map(static fn (RoomAudit $r): array => $r->packageStays, $audit)),
        ]);
        $limit = Days::key(Days::add($today, $this->policy->urgentDays));
        $keyed = [];
        foreach ($changes as $n => $change) {
            $night = Days::key($change->night);
            $property = $this->propertyOfRoom[$change->roomCode] ?? '';
            $keyed[] = [$night <= $limit ? [0, $night, $property, $change->roomCode, '', $n] : [1, '', $property, $change->roomCode, $night, $n], $change];
        }
        usort($keyed, static fn (array $a, array $b): int => self::compare($a[0], $b[0]));

        return array_column($keyed, 1);
    }

    /**
     * Element by element; text as text (a room code "101" sorts before "20", as in the reference).
     *
     * @param list<int|string> $a
     * @param list<int|string> $b
     */
    private static function compare(array $a, array $b): int
    {
        foreach ($a as $i => $value) {
            $order = is_string($value) ? strcmp($value, (string) $b[$i]) : $value <=> $b[$i];
            if ($order !== 0) {
                return $order;
            }
        }

        return 0;
    }

    /**
     * The batch cut into the requests of the writer.
     *
     * @param list<StayChange|ArrivalDepartureChange> $changes
     *
     * @return list<list<StayChange|ArrivalDepartureChange>>
     */
    public function blocks(array $changes): array
    {
        return array_chunk($changes, $this->policy->maxRanges);
    }

    /**
     * The arrival-rule stays of every room, then today's closed-day orphan stays of every room.
     *
     * @param list<RoomAudit> $audit
     *
     * @return list<StayChange>
     */
    private function stayChanges(array $audit): array
    {
        return [
            ...array_merge(...array_map(static fn (RoomAudit $r): array => $r->arrivalStays, $audit)),
            ...array_merge(...array_map(static fn (RoomAudit $r): array => $r->pmsOrphanStays(), $audit)),
        ];
    }

    /**
     * Each write once, the first copy kept (two copies of a write ask the same of the pricing
     * system: that depends only on the room and the field).
     *
     * @param list<StayChange|ArrivalDepartureChange> $changes
     *
     * @return list<StayChange|ArrivalDepartureChange>
     */
    private function unique(array $changes): array
    {
        $seen = [];
        foreach ($changes as $change) {
            $seen[$change->identity()] ??= $change;
        }

        return array_values($seen);
    }
}
