<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use InvalidArgumentException;

/**
 * Data handed to the model, between delimiters, as JSON. Every "<", ">", "&" and quote inside the
 * data is written as a \u escape, so no value (a room name, an alert text, the person's request)
 * can close the delimiter or open a tag of its own: the model sees it as data, never as part of
 * the instructions. Guest data is scrubbed first (PrivacyGuard::scrub).
 */
final class DataBlock
{
    public const TAG_PATTERN = '/^[a-z][a-z_]{0,30}$/';

    private const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function wrap(string $tag, array $data): string
    {
        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new InvalidArgumentException('A data tag is a short lowercase word.');
        }

        return '<' . $tag . ">\n" . json_encode(PrivacyGuard::scrub($data), self::FLAGS) . "\n</" . $tag . '>';
    }
}
