<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\RelationValidator;

final class RelationValidatorTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $bands
     *
     * @return list<string> "path code"
     */
    private static function errors(array $items, array $bands = []): array
    {
        $config = RelationsConfig::fromArray(['relations' => ['items' => $items], 'price_bands' => ['items' => $bands]]);
        $errors = (new RelationValidator())->validate($config, RelationsSample::rooms(), ['inn', 'lodge']);

        return array_map(static fn (ValidationError $error): string => $error->path . ' ' . $error->code, $errors);
    }

    public function testAValidConfigurationHasNoError(): void
    {
        $items = array_map(static fn ($relation): array => $relation->toArray(), RelationsSample::lodgeRelations());
        $items[] = RelationsSample::relationData('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH']);

        self::assertSame([], self::errors($items, [['id' => 'top-band', 'property' => 'inn', 'room' => 'TOP', 'night_types' => ['weekend'], 'min_amount' => 1600, 'max_amount' => 1800]]));
    }

    public function testUnknownPropertiesAndRoomsAreRefused(): void
    {
        self::assertSame(['relations.items.0.property relations.unknown_property'], self::errors([
            RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'LOW'], ['property' => 'nowhere']),
        ]));
        self::assertSame(['relations.items.0.target relations.unknown_room', 'relations.items.0.params.reference relations.unknown_room'], self::errors([
            RelationsSample::relationData('a', 'at_least', 'GHOST', ['reference' => 'SPA'], ['property' => 'inn']),
        ]), 'a room of another property is unknown in this one');
        self::assertSame(['price_bands.items.0.room relations.unknown_room'], self::errors([], [
            ['id' => 'b', 'property' => 'inn', 'room' => 'PLAIN', 'min_amount' => 1, 'max_amount' => 2],
        ]));
    }

    public function testARoomCannotBeComparedWithItself(): void
    {
        self::assertSame(['relations.items.0.params.reference relations.self_reference'], self::errors([
            RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'HIGH']),
        ]));
        self::assertSame(['relations.items.0.params.set.rooms.1 relations.target_in_set'], self::errors([
            RelationsSample::relationData('a', 'premium_over_max', 'TOP', ['set' => ['rooms' => ['LOW', 'TOP']]]),
        ]));
    }

    public function testSwitchLinksMustPointToRelationsOfTheSameRoom(): void
    {
        $items = array_map(static fn ($relation): array => $relation->toArray(), RelationsSample::lodgeRelations());
        $items[0]['params']['cap_relation'] = 'spa-rule';
        $items[0]['params']['suspends'] = ['spa-rule'];
        $items[] = RelationsSample::relationData('spa-rule', 'at_least', 'SPA', ['reference' => 'SPA2']);

        self::assertSame([
            'relations.items.0.params.cap_relation relations.bad_link',
            'relations.items.0.params.suspends.0 relations.bad_link',
        ], self::errors($items));
    }

    public function testACycleNamesItsRoomsAndItsRelations(): void
    {
        $config = RelationsConfig::fromArray(['relations' => ['items' => [
            RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'LOW']),
            RelationsSample::relationData('b', 'premium_over_max', 'LOW', ['set' => ['rooms' => ['MID']]]),
            RelationsSample::relationData('c', 'between', 'MID', ['low_reference' => 'HIGH', 'high_reference' => 'TOP']),
        ]]]);
        $errors = (new RelationValidator())->validate($config, RelationsSample::rooms(), ['inn', 'lodge']);

        self::assertCount(1, $errors);
        self::assertSame('relations.cycle', $errors[0]->code);
        self::assertSame('relations.items.2', $errors[0]->path);
        self::assertStringContainsString('HIGH -> MID -> LOW -> HIGH', $errors[0]->message);
        self::assertStringContainsString('a, b, c', $errors[0]->message);
    }
}
