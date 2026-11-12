<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * The two shapes of key the configuration uses.
 *
 * - code: property ids and room type codes, chosen by the user ("main", "Deluxe-2").
 * - slug: tiers and tags, lowercase ("standard", "tub").
 */
final class Identifier
{
    /** A code without anchors, for building larger patterns. */
    public const CODE_BODY = '[A-Za-z0-9][A-Za-z0-9_-]{0,31}';

    public const CODE_PATTERN = '/^' . self::CODE_BODY . '$/';

    public const SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    public static function isCode(string $value): bool
    {
        return preg_match(self::CODE_PATTERN, $value) === 1;
    }

    public static function isSlug(string $value): bool
    {
        return preg_match(self::SLUG_PATTERN, $value) === 1;
    }
}
