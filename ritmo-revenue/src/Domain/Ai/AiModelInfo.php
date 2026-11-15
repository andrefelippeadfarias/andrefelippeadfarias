<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * A model the customer's key may use, as GET /v1/models lists it.
 */
final class AiModelInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly ?int $maxInputTokens = null,
        public readonly ?int $maxTokens = null,
    ) {
    }
}
