<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Approval;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalGrant;
use Ritmo\Application\Approval\ApprovalKind;
use Ritmo\Application\Approval\ApprovalWindow;
use Ritmo\Application\Approval\ApprovedSubset;
use Ritmo\Application\Approval\PlanEntries;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * The approved write keeps only what the person approved, with the same sent fields
 * (docs/VERIFY.md, section 6).
 */
final class ApprovedSubsetTest extends TestCase
{
    private const BASES = ['Deluxe' => 1000.0, 'Standard' => 500.0];

    public function testOnlyApprovedEntriesWithTheSameFieldsAreKept(): void
    {
        $approved = self::plan([self::item('2027-03-10', 900.0), self::item('2027-03-11', 950.0)]);
        $fresh = self::plan([self::item('2027-03-10', 900.0), self::item('2027-03-11', 940.0), self::item('2027-03-12', 950.0)]);

        $restricted = ApprovedSubset::restrict(self::grant($approved), $fresh, self::BASES);

        self::assertSame(['2027-03-10'], array_map(static fn (PlanItem $i): string => Days::key($i->date), $restricted->plan->items));
        self::assertSame(['item|Deluxe|2027-03-11'], $restricted->needsNewApproval, 'the floor changed since');
        self::assertSame(['item|Deluxe|2027-03-12'], $restricted->notApproved);
    }

    public function testAGroupIsKeptOnlyWhole(): void
    {
        $approved = self::plan([self::item('2027-03-11', 950.0)], [new PlanDeletion('Deluxe', Days::date('2027-03-11'), 'release_ceiling')]);
        $fresh = self::plan([self::item('2027-03-11', 960.0)], [new PlanDeletion('Deluxe', Days::date('2027-03-11'), 'release_ceiling')]);

        $restricted = ApprovedSubset::restrict(self::grant($approved), $fresh, self::BASES);

        self::assertSame([[], []], [$restricted->plan->items, $restricted->plan->deletions]);
        self::assertSame(['item|Deluxe|2027-03-11', 'deletion|Deluxe|2027-03-11'], $restricted->needsNewApproval);
    }

    public function testAPlanOfAnotherDayKeepsNothing(): void
    {
        $approved = self::plan([self::item('2027-03-10', 900.0)]);
        $tomorrow = new Plan('inn', Days::date('2027-03-04'), new DateTimeImmutable('2027-03-04T08:00:00Z'), [self::item('2027-03-10', 900.0)]);

        self::assertSame([], ApprovedSubset::restrict(self::grant($approved), $tomorrow, self::BASES)->plan->items);
    }

    public function testThePlanHashIgnoresExplanationsAndOrder(): void
    {
        $a = PlanEntries::of(self::plan([self::item('2027-03-10', 900.0), self::item('2027-03-11', 950.0)]), self::BASES);
        $b = PlanEntries::of(self::plan([self::item('2027-03-11', 950.0, 'another reason'), self::item('2027-03-10', 900.0)]), self::BASES);
        $c = PlanEntries::of(self::plan([self::item('2027-03-10', 900.0), self::item('2027-03-11', 960.0)]), self::BASES);

        self::assertSame(PlanEntries::hash($a), PlanEntries::hash($b));
        self::assertNotSame(PlanEntries::hash($a), PlanEntries::hash($c));
        self::assertEquals($a[0], PlanEntry::fromArray($a[0]->toArray()));
    }

    public function testAPlanExpiresAtTheLocalMidnightOrWhenItsCalendarIsOld(): void
    {
        $read = new DateTimeImmutable('2027-03-27T20:00:00Z');

        self::assertSame('2027-03-27T23:00:00+00:00', ApprovalWindow::expiresAt(Days::date('2027-03-27'), 'Europe/Berlin', $read, 6)->format(DATE_ATOM), 'midnight in Berlin (UTC+1)');
        self::assertSame('2027-03-28T02:00:00+00:00', ApprovalWindow::expiresAt(Days::date('2027-03-27'), 'America/Sao_Paulo', $read, 6)->format(DATE_ATOM), 'midnight in Sao Paulo (03:00 UTC) is later: the calendar limit');
    }

    private static function grant(Plan $plan): ApprovalGrant
    {
        $entries = PlanEntries::of($plan, self::BASES);

        return new ApprovalGrant(5, 'inn', ApprovalKind::Plan, PlanEntries::hash($entries), $plan->today, 3, 'ritmo_approve', new DateTimeImmutable('2027-03-03T12:10:00Z'), new DateTimeImmutable('2027-03-03T17:00:00Z'), $entries);
    }

    /**
     * @param list<PlanItem>     $items
     * @param list<PlanDeletion> $deletions
     */
    private static function plan(array $items, array $deletions = []): Plan
    {
        return new Plan('inn', Days::date(PlanSample::TODAY), new DateTimeImmutable(PlanSample::NOW), $items, $deletions);
    }

    private static function item(string $night, float $floor, string $text = ''): PlanItem
    {
        return new PlanItem('Deluxe', Days::date($night), $floor, null, null, 'pace_step', [], null, reasonText: $text);
    }
}
