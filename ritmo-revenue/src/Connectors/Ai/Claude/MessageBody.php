<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Ai\Claude;

use Ritmo\Domain\Ai\AiRequest;

/**
 * The body and headers of POST /v1/messages, built from a whitelist: model, max_tokens, the system
 * blocks (the last stable one is the cache breakpoint), one user message, output_config (effort,
 * and the JSON schema of a structured answer) and the server-side fallback. Never temperature,
 * top_p, top_k, thinking, budget_tokens, tool_choice or an assistant prefill: this API version and
 * these models refuse them.
 */
final class MessageBody
{
    public const KEYS = ['model', 'max_tokens', 'system', 'messages', 'output_config', 'fallbacks'];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(AiRequest $request, ClaudeConfig $config): array
    {
        $system = [];
        $last = count($request->system) - 1;
        foreach ($request->system as $index => $text) {
            $block = ['type' => 'text', 'text' => $text];
            if ($index === $last) {
                $block['cache_control'] = ['type' => 'ephemeral'];
            }
            $system[] = $block;
        }
        $output = ['effort' => $config->effort->value];
        if ($request->outputSchema !== null) {
            $output['format'] = ['type' => 'json_schema', 'schema' => $request->outputSchema];
        }
        $body = [
            'model' => $config->model,
            'max_tokens' => $request->maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $request->user]],
            'output_config' => $output,
        ];
        if ($config->fallback) {
            $body['fallbacks'] = 'default';
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(ClaudeConfig $config, bool $withBody): array
    {
        $headers = ['x-api-key' => $config->apiKey(), 'anthropic-version' => ClaudeConfig::API_VERSION, 'accept' => 'application/json'];
        if ($withBody) {
            $headers['content-type'] = 'application/json';
            if ($config->fallback) {
                $headers['anthropic-beta'] = ClaudeConfig::FALLBACK_BETA;
            }
        }

        return $headers;
    }

    /**
     * The body as sent (JSON).
     *
     * @param array<string, mixed> $body
     */
    public static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
