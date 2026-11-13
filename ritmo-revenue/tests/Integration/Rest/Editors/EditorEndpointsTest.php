<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Rest\Editors;

require_once dirname(__DIR__, 3) . '/Unit/Admin/Editors/stubs.php';
require_once __DIR__ . '/stubs.php';

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Rest\Editors\EditorEndpoints;
use Ritmo\Tests\Unit\Application\Rules\RulesWorld;

/**
 * The answers of the rules REST routes over a real database (SQLite): the versions with who and
 * when, and what changed between two versions in words: labels and values with their unit, never
 * a dotted key, a snake_case name or JSON of the rules.
 */
final class EditorEndpointsTest extends TestCase
{
    public function testTheVersionsSayWhoSavedThemAndHowManyValuesChanged(): void
    {
        $world = self::worldWithTwoVersions();

        [$status, $body] = self::endpoints($world)->versions(1);

        self::assertSame(200, $status);
        self::assertSame([2, 20], [$body['total'], $body['per_page']]);
        self::assertSame([[2, 'Person 7', 'Goal of the week', 1, true], [1, 'The plugin', 'Neutral defaults', null, false]], array_map(
            static fn (array $v): array => [$v['version'], $v['by'], $v['note'], $v['changes'], $v['in_force']],
            $body['versions']
        ));
        self::assertSame('2026-11-13 13:00', $body['versions'][0]['saved_at']);
        self::assertSame([], self::endpoints($world)->versions(9)[1]['versions'], 'a page after the last is empty');
    }

    public function testTwoVersionsCompareInWords(): void
    {
        $world = self::worldWithTwoVersions();

        [$status, $body] = self::endpoints($world)->compare(1, 2);

        self::assertSame(200, $status);
        self::assertSame('1 change from version 1 to version 2.', $body['text']);
        self::assertCount(1, $body['changes']);
        $change = $body['changes'][0];
        self::assertSame(['60%', '65%'], [$change['before'], $change['after']]);
        self::assertStringNotContainsString('.', $change['label'], 'a label, not a dotted key');
        self::assertStringNotContainsString('_', (string) json_encode($body['changes']), 'no snake_case name');

        [$status, $same] = self::endpoints($world)->compare(2, 2);
        self::assertSame([200, [], 'These two versions hold the same values.'], [$status, $same['changes'], $same['text']]);
    }

    public function testAnUnknownVersionIsNotFoundWithASentence(): void
    {
        $world = self::worldWithTwoVersions();

        self::assertSame(
            [404, ['code' => 'ritmo_unknown_version', 'message' => 'That version of the rules cannot be read.']],
            self::endpoints($world)->compare(1, 99)
        );
    }

    private static function worldWithTwoVersions(): RulesWorld
    {
        $world = new RulesWorld();
        $rules = $world->rules();
        $rules['occupancy']['goals'][7] = 65.0;
        $world->editor->save($rules, 1, $world->hash(), 7, 'Goal of the week', null, false);

        return $world;
    }

    private static function endpoints(RulesWorld $world): EditorEndpoints
    {
        return new EditorEndpoints(
            $world->history,
            static fn (int $user): string => $user === 0 ? 'The plugin' : 'Person ' . $user,
            static fn (DateTimeImmutable $at): string => $at->format('Y-m-d H:i'),
            'BRL',
        );
    }
}
