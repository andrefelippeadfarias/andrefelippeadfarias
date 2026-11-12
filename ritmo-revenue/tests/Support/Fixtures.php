<?php

declare(strict_types=1);

namespace Ritmo\Tests\Support;

use Ritmo\Domain\Config\Preset;
use Ritmo\Domain\Config\PresetLoader;
use Ritmo\Domain\Config\RuleSet;
use RuntimeException;

/**
 * Paths and ready-made objects of the test fixtures.
 */
final class Fixtures
{
    private static ?Preset $parity = null;

    /**
     * Absolute path of the plugin folder.
     */
    public static function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Absolute path of a file under tests/fixtures/.
     */
    public static function path(string $relative): string
    {
        return dirname(__DIR__) . '/fixtures/' . ltrim($relative, '/');
    }

    /**
     * A fixture file decoded from JSON.
     *
     * @return array<int|string, mixed>
     */
    public static function json(string $relative): array
    {
        $text = file_get_contents(self::path($relative));
        if ($text === false) {
            throw new RuntimeException(sprintf('Cannot read the fixture "%s".', $relative));
        }

        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The parity preset: the reference engine's configuration converted to the plugin's format
     * (tests/fixtures/presets/parity.json). Golden tests compute from it.
     */
    public static function parityPreset(): Preset
    {
        return self::$parity ??= (new PresetLoader())->fromFile(self::path('presets/parity.json'));
    }

    public static function parityRules(): RuleSet
    {
        return self::parityPreset()->rules;
    }
}
