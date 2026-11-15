<?php

declare(strict_types=1);

namespace Ritmo\Tests\Support;

/**
 * A translation table for the namespaced __() stand-ins of the unit tests: a test sets the texts
 * of a fake locale and resets them in tearDown, so a text that skips the translation call shows
 * up in English.
 */
final class FakeTranslations
{
    /** @var array<string, string> English => translated */
    public static array $texts = [];

    private function __construct()
    {
    }

    public static function of(string $text): string
    {
        return self::$texts[$text] ?? $text;
    }
}
