<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * One answer of the model, as the connector read it: the text of the first text block, why it
 * stopped, the refusal details (only for a refusal), the tokens it used, the model that answered
 * and whether the server-side fallback answered instead of the model asked.
 */
final class AiReply
{
    public const STOP_END_TURN = 'end_turn';
    public const STOP_MAX_TOKENS = 'max_tokens';
    public const STOP_REFUSAL = 'refusal';

    public function __construct(
        public readonly string $text,
        public readonly string $stopReason,
        public readonly TokenUsage $usage,
        public readonly string $model,
        public readonly bool $fallbackUsed = false,
        public readonly ?string $refusalCategory = null,
        public readonly ?string $refusalExplanation = null,
    ) {
    }

    /**
     * A complete answer that may be used: it ended its turn and is not empty.
     */
    public function isComplete(): bool
    {
        return in_array($this->stopReason, [self::STOP_END_TURN, 'stop_sequence'], true) && trim($this->text) !== '';
    }
}
