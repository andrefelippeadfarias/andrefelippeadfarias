<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationStage;
use Ritmo\Domain\Relations\RelationValidator;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\ParityNames;
use Ritmo\Tests\Unit\Domain\Relations\Golden\ParityRelations;

/**
 * The parity fixture of the relations is a valid configuration of the parity preset's rooms and
 * resolves in the reference engine's fixed order.
 */
final class ParityRelationsTest extends TestCase
{
    public function testTheParityRelationsValidateAgainstTheParityRooms(): void
    {
        $preset = Fixtures::parityPreset();
        $properties = array_map(static fn (Property $property): string => $property->id, $preset->properties);

        self::assertSame([], (new RelationValidator())->validate(ParityRelations::config(), $preset->roomTypes, $properties));
    }

    public function testTheParityRelationsRunInTheEngineOrder(): void
    {
        $graph = new RelationGraph(ParityRelations::config()->relations, ParityRelations::rooms());
        $ids = static fn (array $relations): array => array_map(static fn (Relation $relation): string => $relation->id, $relations);

        self::assertSame([
            ParityNames::relation('at_least'), ParityNames::relation('between'), ParityNames::relation('premium'),
            ParityNames::relation('switch'), ParityNames::relation('band'),
        ], $ids($graph->orderedFor(RelationStage::Coherence)));
        self::assertSame([ParityNames::relation('between'), ParityNames::relation('derive'), ParityNames::relation('premium')], $ids($graph->orderedFor(RelationStage::Audit)));
    }
}
