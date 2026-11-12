<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Relations\Audit\RelationAuditor;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Coherence\NightSet;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

final class NightSetAndAuditTest extends TestCase
{
    public function testTheNightSetIsTheTouchedNightsPlusTheHorizonOrTheLightFilter(): void
    {
        $graph = new RelationGraph(RelationsSample::lodgeRelations(), RelationsSample::rooms());
        $calendar = RelationsSample::calendar(['PLAIN' => ['2027-02-28' => [900, 0], '2027-03-01' => [900, 0], '2027-03-31' => [900, 0], '2027-04-01' => [900, 0]]]);
        $keys = static fn (array $nights): array => array_map(Days::key(...), $nights);
        $today = Days::date(RelationsSample::TODAY);

        $full = new NightFacts(RelationsSample::rooms(), $calendar, ['LOW|2027-05-01' => 1000.0]);
        self::assertSame(['2027-03-01', '2027-03-31', '2027-05-01'], $keys(NightSet::of($graph, $full, $today, 30)), 'yesterday and 31 days out are outside the horizon');
        $light = new NightFacts(RelationsSample::rooms(), $calendar, ['LOW|2027-05-01' => 1000.0], [], ['SPA|2027-03-02']);
        self::assertSame(['2027-03-02', '2027-05-01'], $keys(NightSet::of($graph, $light, $today, 30)));
    }

    public function testNightFactsFollowTheDecidedThenLadderThenCalendarPrice(): void
    {
        $night = Days::date(RelationsSample::NIGHT);
        $calendar = RelationsSample::calendar(['LOW' => [RelationsSample::NIGHT => [1000, 4, 0, 0]], 'HIGH' => [RelationsSample::NIGHT => [1200, 1, 1100, 1500]]]);
        $facts = new NightFacts(RelationsSample::rooms(), $calendar, [], ['LOW|' . RelationsSample::NIGHT => 950.0, 'HIGH|' . RelationsSample::NIGHT => 1300.0], ['HIGH|' . RelationsSample::NIGHT]);

        self::assertSame(1000.0, $facts->price('LOW', $night), 'a light run uses the ladder price only inside its filter');
        self::assertSame(1300.0, $facts->price('HIGH', $night));
        $facts->decide('HIGH', $night, 1250.0);
        self::assertSame(1250.0, $facts->price('HIGH', $night));
        $facts->forget('HIGH', $night);
        self::assertNull($facts->price('MID', $night), 'no calendar row');
        self::assertFalse($facts->isFree('LOW', $night), 'sold out');
        self::assertSame(2, $facts->unitsLeft('HIGH', $night));
        self::assertNull($facts->floorInForce('LOW', $night));
        self::assertNull($facts->ceilingInForce('LOW', $night));
        self::assertSame([1100.0, 1500.0], [$facts->floorInForce('HIGH', $night), $facts->ceilingInForce('HIGH', $night)]);
    }

    public function testTheAuditListsTheNightsOutOfTheRelationAndProposesItems(): void
    {
        $relations = [
            RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75], ['stages' => ['coherence', 'audit'], 'order_ref' => 'standing']),
            RelationsSample::relation('top', 'premium_over_max', 'TOP', ['set' => ['room_tags' => ['tub']], 'premium_percent' => ['weekday' => 20]], ['stages' => ['audit']]),
        ];
        $calendar = RelationsSample::calendar([
            'LOW' => ['2027-03-02' => [1300, 1], '2027-03-03' => [1300, 1], '2027-03-04' => [1300, 4]],
            'HIGH' => ['2027-03-02' => [1400, 1], '2027-03-03' => [1400, 1], '2027-03-04' => [1400, 1]],
            'MID' => ['2027-03-02' => [1700, 0], '2027-03-03' => [1350, 0], '2027-03-04' => [1900, 0]],
            'TOP' => ['2027-03-02' => [1500, 0], '2027-03-03' => [1700, 0], '2027-03-04' => [1500, 0]],
        ]);
        $auditor = new RelationAuditor(new RelationGraph($relations, RelationsSample::rooms()), new RelationSettings(), new NightFacts(RelationsSample::rooms(), $calendar), (new StubHooks())->bundle(), Days::date(RelationsSample::TODAY));

        $findings = $auditor->run(new DateRange(Days::date('2027-03-02'), Days::date('2027-03-05')));
        $rows = array_map(static fn ($f): array => [$f->room, Days::key($f->night), $f->floor, $f->ceiling], $findings);

        self::assertSame([
            ['MID', '2027-03-02', 1300.0, 1375.0],
            ['TOP', '2027-03-02', 1680.0, null],
            ['TOP', '2027-03-04', 1680.0, null],
        ], $rows, 'the 3rd: LOW sold out, so MID is not reported; TOP compares with HIGH and the corrected MID; the 5th has no rows');
        $items = array_map(static fn (DraftItem $item): array => $item->toArray(), $auditor->toPlanItems($findings));
        self::assertSame(['MID', 1300.0, 1375.0, 'standing', 1700.0], [$items[0]['room'], $items[0]['floor'], $items[0]['ceiling'], $items[0]['order_ref'], $items[0]['price_before']]);
        self::assertNull($items[1]['order_ref']);
    }
}
