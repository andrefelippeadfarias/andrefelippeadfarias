<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Execute\StoredCheck;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Journal\StoredState;
use Ritmo\Application\Write\Resume\NightClass;
use Ritmo\Application\Write\Resume\ResumePlanner;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

/**
 * The read-back of a written step (parity with beds24._conferir) and the classification of
 * unsettled journal rows by the resume: applied, not applied or diverged, never by the old payload.
 * Channel limits are not compared yet: the inventory read has no channel limits (WRITE.md).
 */
final class ReadBackTest extends TestCase
{
    private const RESTRICTIONS = ['none' => Restriction::None, 'noCheckIn' => Restriction::NoCheckin, 'noCheckOut' => Restriction::NoCheckout, 'noCheckInOrCheckOut' => Restriction::NoCheckinCheckout];

    /**
     * @return array<string, array{array<string, mixed>, array<string, int>}>
     */
    public static function conferir(): array
    {
        $cases = [];
        foreach (Fixtures::json('golden/m3/write/beds24._conferir.json')['cases'] as $case) {
            $cases[$case['name']] = [$case['input'], $case['expected']];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, int>   $expected
     */
    #[DataProvider('conferir')]
    public function testThePmsReadBackIsTheReferences(array $input, array $expected): void
    {
        $changes = [];
        foreach ($input['items'] as $item) {
            $changes[] = new CalendarChange(
                'Room',
                $input['rooms'][$item['quarto']]['room_id'],
                Days::date($item['de']),
                Days::date($item['ate']),
                $item['min_stay'] ?? null,
                $item['max_stay'] ?? null,
                isset($item['override']) ? self::RESTRICTIONS[$item['override']] : null,
                $item['num_avail'] ?? null,
                array_map(static fn (mixed $v): ?int => $v === null ? null : (int) $v, $item['canais_max'] ?? []),
                expectedAvailable: $item['num_avail_esperado'] ?? null,
            );
        }
        $nights = [];
        foreach ($changes as $change) {
            $nights[$change->roomId] = [...($nights[$change->roomId] ?? []), ...$change->nights()];
        }
        $step = new WriteStep(1, WriteOperation::PmsCalendar, 'Room', $nights, array_map(static fn (CalendarChange $c): array => $c->range(), $changes), 'pms 1/1', '', $changes);
        $reread = [];
        foreach ($input['reread'] as $code => $byNight) {
            $reread[$input['rooms'][$code]['room_id']] = $byNight;
        }

        [$status] = StoredCheck::compare((new StoredState())->intended($step), new StoredState([], $reread), true);

        self::assertSame($expected['items_with_error'] === 0 ? OpStatus::Stored : OpStatus::Mismatch, $status);
    }

    public function testTheResumeClassifiesEachNightAgainstTheStateReadNow(): void
    {
        $row = static fn (int $id, string $operation, string $status, array $intended, array $before, string $group = ''): array => [
            'id' => $id, 'operation' => $operation, 'status' => $status, 'group_key' => $group, 'target_ref' => 'L1',
            'intended_state' => $intended, 'before_state' => $before,
            'payload' => ['room' => 'Deluxe', 'nights' => ['L1' => array_keys($intended['L1'] ?? [])], 'entries' => [['date' => '2027-03-10', 'min_price' => '-9']]],
        ];
        $want = static fn (int $min): array => ['state' => ['min_price' => $min, 'max_price' => null, 'min_stay' => null, 'check_in' => null, 'check_out' => null], 'fields' => ['min_price']];
        $rows = [
            $row(1, 'overrides_write', 'sending', ['L1' => ['2027-03-10' => $want(-9)]], ['L1' => ['2027-03-10' => null]]),
            $row(2, 'overrides_write', 'unknown', ['L1' => ['2027-03-11' => $want(-9)]], ['L1' => ['2027-03-11' => ['min_price' => -2]]]),
            $row(3, 'overrides_write', 'accepted', ['L1' => ['2027-03-12' => $want(-9)]], ['L1' => ['2027-03-12' => null]]),
            $row(4, 'overrides_write', 'planned', ['L1' => ['2027-03-13' => $want(-9)]], ['L1' => ['2027-03-13' => null]]),
        ];
        $fresh = new StoredState(['L1' => [
            '2027-03-10' => ['min_price' => -9, 'max_price' => null],
            '2027-03-11' => ['min_price' => -2],
            '2027-03-12' => ['min_price' => 15, 'fixed' => true],
            '2027-03-13' => ['min_price' => -9],
        ]]);

        $report = (new ResumePlanner())->classify($rows, $fresh);

        self::assertSame([1 => OpStatus::Stored, 2 => OpStatus::Abandoned, 3 => OpStatus::Mismatch, 4 => OpStatus::Abandoned], $report->rows);
        self::assertSame(NightClass::Diverged->value, $report->nights[3]['L1']['2027-03-12'], 'someone else changed it');
        self::assertSame([], $report->nights[4], 'a step never sent is not read at all');
        self::assertSame([1], $report->applied());
        self::assertSame([['L1' => ['2027-03-10', '2027-03-11', '2027-03-12', '2027-03-13']], []], ResumePlanner::targets($rows));
    }

    public function testADeletionOfAGroupIsAppliedWhenItsNightHoldsTheRewrite(): void
    {
        $rewrite = ['state' => ['min_price' => 5, 'max_price' => 66, 'min_stay' => null, 'check_in' => null, 'check_out' => null], 'fields' => ['min_price', 'max_price', 'min_stay', 'check_in', 'check_out', 'fixed']];
        $rows = [
            ['id' => 1, 'operation' => 'overrides_delete', 'status' => 'stored', 'group_key' => 'g1', 'target_ref' => 'L1', 'intended_state' => ['L1' => ['2027-03-05' => ['state' => null, 'fields' => []]]], 'before_state' => ['L1' => ['2027-03-05' => ['min_price' => 25, 'max_price' => 80]]], 'payload' => ['room' => 'Deluxe', 'nights' => ['L1' => ['2027-03-05']], 'entries' => ['2027-03-05']]],
            ['id' => 2, 'operation' => 'overrides_write', 'status' => 'stored', 'group_key' => 'g1', 'target_ref' => 'L1', 'intended_state' => ['L1' => ['2027-03-05' => $rewrite]], 'before_state' => ['L1' => ['2027-03-05' => null]], 'payload' => ['room' => 'Deluxe', 'nights' => ['L1' => ['2027-03-05']], 'entries' => [['date' => '2027-03-05', 'min_price' => '5', 'max_price' => '66']]]],
        ];

        $whole = (new ResumePlanner())->classify($rows, new StoredState(['L1' => ['2027-03-05' => ['min_price' => 5, 'max_price' => 66, 'min_stay' => null, 'check_in' => null, 'check_out' => null]]]));
        self::assertSame([1 => OpStatus::Stored, 2 => OpStatus::Stored], $whole->rows);
        self::assertSame([], $whole->broken);

        $rows[1]['status'] = 'abandoned';
        $half = (new ResumePlanner())->classify($rows, new StoredState(['L1' => ['2027-03-05' => null]]));
        self::assertCount(1, $half->broken);
        self::assertSame('Deluxe', $half->broken[0]->roomCode);
    }
}
