<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * The Claude models the settings screen offers. The customer chooses (the cost is theirs); the
 * id is kept as text, checked against this list and, once the connection was tested, against the
 * models the customer's key may use (GET /v1/models).
 */
final class AiModel
{
    public const OPUS = 'claude-opus-5-5';
    public const SONNET = 'claude-sonnet-5-5';
    public const HAIKU = 'claude-haiku-5-5';

    public const DEFAULT = self::OPUS;

    /** The choices of the settings screen, in their order. */
    public const CHOICES = [self::OPUS, self::SONNET, self::HAIKU];

    /** Any model id an answer may name (a fallback model included). */
    public const ID_PATTERN = '/^claude-[a-z0-9][a-z0-9.-]{0,62}$/';

    private function __construct()
    {
    }

    public static function isChoice(string $id): bool
    {
        return in_array($id, self::CHOICES, true);
    }

    public static function isWellFormed(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }
}
