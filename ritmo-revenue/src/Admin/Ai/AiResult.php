<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

/**
 * The last text the assistant wrote for a person (the weekly report in words), kept ten minutes
 * for the screen the form returns to and shown once. Answers are never stored for longer.
 */
final class AiResult
{
    private const LIFETIME_SECONDS = 600;

    private function __construct()
    {
    }

    public static function keep(string $title, string $text, string $model, bool $fallback): void
    {
        set_transient(self::key(), ['title' => $title, 'text' => $text, 'model' => $model, 'fallback' => $fallback], self::LIFETIME_SECONDS);
    }

    /**
     * @return array{title: string, text: string, model: string, fallback: bool}|null
     */
    public static function take(): ?array
    {
        $stored = get_transient(self::key());
        delete_transient(self::key());
        if (!is_array($stored) || !is_string($stored['text'] ?? null)) {
            return null;
        }

        return ['title' => (string) ($stored['title'] ?? ''), 'text' => $stored['text'], 'model' => (string) ($stored['model'] ?? ''), 'fallback' => (bool) ($stored['fallback'] ?? false)];
    }

    private static function key(): string
    {
        return 'ritmo_ai_result_' . get_current_user_id();
    }
}
