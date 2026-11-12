<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationStage;

final class RelationGraphTest extends TestCase
{
    /**
     * @return list<Relation>
     */
    private static function chain(): array
    {
        return [
            RelationsSample::relation('top-premium', 'premium_over_max', 'TOP', ['set' => ['room_tags' => ['tub']], 'premium_percent' => ['weekday' => 20]], ['priority' => 30]),
            RelationsSample::relation('mid-between', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75], ['priority' => 20]),
            RelationsSample::relation('high-at-least-low', 'at_least', 'HIGH', ['reference' => 'LOW'], ['priority' => 10]),
            ...RelationsSample::lodgeRelations(),
        ];
    }

    public function testRelationsRunAfterTheRelationsThatTargetTheirInputsAndGroupsRunAsBlocks(): void
    {
        $graph = new RelationGraph(array_reverse(self::chain()), RelationsSample::rooms());

        self::assertSame(
            ['high-at-least-low', 'mid-between', 'top-premium', 'plain-switch', 'plain-band'],
            array_map(static fn (Relation $relation): string => $relation->id, $graph->ordered())
        );
        self::assertSame(['plain-switch', 'plain-band'], array_map(static fn (Relation $relation): string => $relation->id, $graph->orderedFor(RelationStage::Validate)));
    }

    public function testSetsResolveAndGroupsLinkEveryRoomOfARelation(): void
    {
        $graph = new RelationGraph(self::chain(), RelationsSample::rooms());
        $group = $graph->groupOf('TOP');
        sort($group);

        self::assertSame(['HIGH', 'LOW', 'MID', 'TOP'], $group);
        self::assertSame(['LOW', 'HIGH', 'MID'], $graph->members($graph->relation('top-premium')));
        self::assertSame(['SPA', 'SPA2'], $graph->triggerMembers($graph->relation('plain-switch')));
        self::assertSame(['SPA', 'SPA2'], $graph->priceInputs($graph->relation('plain-switch')), 'the switch reads the prices of its capping band');
        self::assertSame(['NONE'], $graph->groupOf('NONE'));
    }

    public function testAGroupRunsAsABlockEvenWhenItsRoomsAreNotContiguous(): void
    {
        $relations = [
            RelationsSample::relation('spa2-over-spa', 'at_least', 'SPA2', ['reference' => 'SPA']),
            RelationsSample::relation('top-over-low', 'at_least', 'TOP', ['reference' => 'LOW']),
            RelationsSample::relation('mid-over-high', 'at_least', 'MID', ['reference' => 'HIGH']),
        ];
        $order = array_map(static fn (Relation $relation): string => $relation->id, (new RelationGraph($relations, RelationsSample::rooms()))->ordered());

        self::assertSame(['top-over-low', 'mid-over-high', 'spa2-over-spa'], $order, 'LOW-TOP first (LOW is room 0), then HIGH-MID (room 1), then the lodge');
    }

    public function testACycleIsRefusedWithTheRoomsOfTheCircle(): void
    {
        $relations = [
            RelationsSample::relation('a', 'at_least', 'HIGH', ['reference' => 'LOW']),
            RelationsSample::relation('b', 'at_least', 'MID', ['reference' => 'HIGH']),
            RelationsSample::relation('c', 'at_least', 'LOW', ['reference' => 'MID']),
        ];

        try {
            new RelationGraph($relations, RelationsSample::rooms());
            self::fail('A cycle must be refused.');
        } catch (InvalidConfig $refused) {
            self::assertSame('relations.cycle', $refused->errors()[0]->code);
            self::assertStringContainsString('HIGH -> MID -> LOW -> HIGH', $refused->errors()[0]->message);
        }
    }

    public function testATriggerSetAddsNoEdgeAndADisabledRelationIsIgnored(): void
    {
        $relations = [
            RelationsSample::relation('plain-hold', 'occupancy_switch', 'PLAIN', ['trigger' => ['set' => ['room_tags' => ['tub']], 'threshold' => 80], 'then' => ['kind' => 'hold']]),
            RelationsSample::relation('spa-over-plain', 'at_least', 'SPA', ['reference' => 'PLAIN']),
            RelationsSample::relation('off', 'at_least', 'PLAIN', ['reference' => 'SPA'], ['enabled' => false]),
        ];
        $graph = new RelationGraph($relations, RelationsSample::rooms());

        self::assertNull($graph->relation('off'));
        self::assertCount(2, $graph->ordered(), 'the switch only counts the occupancy of SPA, so SPA >= PLAIN is no cycle');
        self::assertSame([], $graph->priceInputs($graph->relation('plain-hold')));
    }
}
