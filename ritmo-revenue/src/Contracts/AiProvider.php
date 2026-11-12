<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use Ritmo\Contracts\Exception\ConnectorException;

/**
 * An optional language model, used with the customer's own API key.
 *
 * The AI only writes text and drafts. It never writes a price: a draft becomes a rule or an
 * order only after a person approves it and the validator accepts it. Prompts must not contain
 * guest data.
 */
interface AiProvider
{
    /**
     * Stable id of the provider ("claude").
     */
    public function id(): string;

    /**
     * Returns the model's text answer for a conversation.
     *
     * @param string                                      $system    system instructions
     * @param list<array{role: string, content: string}> $messages  turns, oldest first; role is "user" or "assistant"
     * @param int                                         $maxTokens upper bound of the answer
     *
     * @throws ConnectorException
     */
    public function complete(string $system, array $messages, int $maxTokens): string;
}
