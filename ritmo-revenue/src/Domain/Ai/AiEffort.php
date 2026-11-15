<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * How much the model thinks before it answers (output_config.effort). Sent on every request:
 * the default differs between models, so the plugin never relies on it.
 */
enum AiEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public const DEFAULT = self::Medium;
}
