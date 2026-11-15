<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use RuntimeException;

/**
 * The assistant gave nothing usable, for a reason a person can act on. The message is a plain
 * English sentence safe to show (no key, no prompt, no guest data); the admin layer translates
 * it by its reason.
 */
final class AiStopped extends RuntimeException
{
    public const DISABLED = 'disabled';
    public const NO_KEY = 'no_key';
    public const BUDGET = 'budget';
    public const MODEL_UNAVAILABLE = 'model_unavailable';
    public const PRIVACY = 'privacy';
    public const REFUSAL = 'refusal';
    public const MAX_TOKENS = 'max_tokens';
    public const INVALID_OUTPUT = 'invalid_output';
    public const UNKNOWN_NUMBER = 'unknown_number';
    public const DRAFT_REFUSED = 'draft_refused';
    public const NOT_SUPPORTED = 'not_supported';
    public const PROVIDER = 'provider';

    /**
     * @param array<string, string|int|float|list<string>> $details facts safe to show (codes, numbers, refusal category)
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
