<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;

/**
 * The goldens of the ladder area match their frozen inputs, a committed reference engine and the
 * parity fixture they were generated with.
 */
final class LadderGoldenManifestTest extends TestCase
{
    public function testTheGoldensMatchTheirInputs(): void
    {
        $manifest = Reference::manifest();

        self::assertFalse($manifest['engine_dirty'], 'the goldens came from uncommitted engine code: commit it and run golden_ladder.py again');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest['engine_commit']);
        foreach ($manifest['frozen_inputs'] as $file => $sha256) {
            self::assertSame($sha256, hash_file('sha256', Fixtures::path('frozen/' . $file)), "frozen/$file changed after the goldens were generated");
        }
        self::assertSame($manifest['parity_fixture']['sha256'], hash_file('sha256', Fixtures::path($manifest['parity_fixture']['file'])));
    }

    public function testEveryListedFileIsPresentWithItsCases(): void
    {
        $files = Reference::manifest()['files'];

        self::assertGreaterThanOrEqual(31, count($files));
        foreach ($files as $file) {
            self::assertCount($file['cases'], Reference::cases(basename($file['file'], '.json')), $file['file']);
        }
        $onDisk = array_map('basename', glob(Fixtures::path('golden/m2/ladder/*.json')) ?: []);
        self::assertEqualsCanonicalizing([...array_column($files, 'file'), 'MANIFEST.json'], $onDisk);
    }
}
