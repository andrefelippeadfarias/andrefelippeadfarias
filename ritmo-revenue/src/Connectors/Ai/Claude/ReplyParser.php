<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Ai\Claude;

use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Ai\AiModel;
use Ritmo\Domain\Ai\AiModelInfo;
use Ritmo\Domain\Ai\AiReply;
use Ritmo\Domain\Ai\TokenUsage;

/**
 * Reads the answers of the Claude API: a message (the text of its first "text" block; "thinking"
 * blocks, which come empty, are skipped; a "fallback" block or a "fallback_message" iteration of
 * the usage says the fallback model answered), its stop reason and refusal details, the usage and
 * the model that answered; and the list of models.
 */
final class ReplyParser
{
    private const EXPLANATION_MAX = 300;

    private function __construct()
    {
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @throws ConnectorException when the message does not have the documented shape
     */
    public static function message(array $body): AiReply
    {
        $content = $body['content'] ?? null;
        $stop = $body['stop_reason'] ?? null;
        if (!is_array($content) || !array_is_list($content) || !is_string($stop) || preg_match('/^[a-z_]{1,40}$/', $stop) !== 1) {
            throw HttpFailure::unexpectedShape(ClaudeProvider::ID, ClaudeProvider::LABEL, 'POST ' . ClaudeConfig::MESSAGES_PATH, 'no content or stop reason');
        }
        $text = null;
        $fallback = false;
        foreach ($content as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if ($type === 'fallback') {
                $fallback = true;
            }
            if ($type === 'text' && $text === null && is_string($block['text'] ?? null)) {
                $text = $block['text'];
            }
        }
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        foreach (is_array($usage['iterations'] ?? null) ? $usage['iterations'] : [] as $iteration) {
            if (is_array($iteration) && ($iteration['type'] ?? null) === 'fallback_message') {
                $fallback = true;
            }
        }
        $details = is_array($body['stop_details'] ?? null) ? $body['stop_details'] : [];
        $refusal = $stop === AiReply::STOP_REFUSAL;
        $category = $refusal && is_string($details['category'] ?? null) && preg_match('/^[a-z_]{1,40}$/', $details['category']) === 1 ? $details['category'] : null;
        $explanation = $refusal && is_string($details['explanation'] ?? null) ? mb_substr($details['explanation'], 0, self::EXPLANATION_MAX) : null;
        $model = is_string($body['model'] ?? null) && AiModel::isWellFormed($body['model']) ? $body['model'] : 'unknown';

        return new AiReply($text ?? '', $stop, TokenUsage::fromApi($usage), $model, $fallback, $category, $explanation);
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return list<AiModelInfo>
     *
     * @throws ConnectorException
     */
    public static function models(array $body): array
    {
        $data = $body['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            throw HttpFailure::unexpectedShape(ClaudeProvider::ID, ClaudeProvider::LABEL, 'GET ' . ClaudeConfig::MODELS_PATH, 'no list of models');
        }
        $models = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !AiModel::isWellFormed($row['id'])) {
                continue;
            }
            $models[] = new AiModelInfo(
                $row['id'],
                is_string($row['display_name'] ?? null) ? mb_substr($row['display_name'], 0, 80) : $row['id'],
                is_int($row['max_input_tokens'] ?? null) ? $row['max_input_tokens'] : null,
                is_int($row['max_tokens'] ?? null) ? $row['max_tokens'] : null,
            );
        }

        return $models;
    }

    /**
     * The machine type of an error body ({"type": "error", "error": {"type": ...}}), when it is one.
     */
    public static function errorType(string $body): ?string
    {
        $decoded = json_decode($body, true);
        $type = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['type'] ?? null) : null;

        return is_string($type) && preg_match('/^[a-z_]{1,40}$/', $type) === 1 ? $type : null;
    }
}
