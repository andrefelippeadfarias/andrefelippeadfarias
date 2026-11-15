<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use InvalidArgumentException;

/**
 * One question to the model: the fixed system blocks of its purpose (the last one is the cache
 * breakpoint), one user message with the data between delimiters, and the JSON schema of the
 * answer when the answer is structured.
 */
final class AiRequest
{
    public const MAX_TOKENS = 16000;

    /**
     * @param list<string>              $system       stable text blocks; nothing variable (no date, no id)
     * @param array<string, mixed>|null $outputSchema JSON schema of a structured answer; null for text
     * @param list<string>              $known        names of the property and its rooms in
     *                                                the data sent (PrivacyGuard::names()): the
     *                                                answer may use them, never taken for a person
     */
    public function __construct(
        public readonly AiPurpose $purpose,
        public readonly array $system,
        public readonly string $user,
        public readonly ?array $outputSchema = null,
        public readonly int $maxTokens = self::MAX_TOKENS,
        public readonly array $known = [],
    ) {
        if ($system === [] || array_filter($system, static fn (mixed $b): bool => !is_string($b) || trim($b) === '') !== []) {
            throw new InvalidArgumentException('A request has at least one system block, none of them empty.');
        }
        if (trim($user) === '') {
            throw new InvalidArgumentException('A request has a user message.');
        }
        if ($maxTokens < 1 || $maxTokens > self::MAX_TOKENS) {
            throw new InvalidArgumentException(sprintf('max_tokens is from 1 to %d without streaming.', self::MAX_TOKENS));
        }
    }
}
