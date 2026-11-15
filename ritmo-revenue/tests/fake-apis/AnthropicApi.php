<?php
/**
 * Fake Claude API for tests, in the format of the real one (API version 2026-10-21):
 *
 *   RITMO_FAKE_ANTHROPIC_STATE=/tmp/anthropic.json php -S 127.0.0.1:8098 tests/fake-apis/AnthropicApi.php
 *
 * or in process: (new AnthropicApi($stateFile))->handle($method, $path, $headers, $body).
 *
 * Routes (also under a "/anthropic" prefix, the base URL a site points at):
 *   POST /v1/messages   checks what the real API checks (version header, key, no sampling or
 *                       thinking parameters, no assistant prefill, no forced tool choice, the
 *                       fallback beta header with "fallbacks", the schema of a structured answer)
 *                       and answers a message: a "thinking" block left empty, then the text; the
 *                       usage with cache writes the first time a system prompt is seen and cache
 *                       reads after
 *   GET  /v1/models     the models the key may use (a key containing "haiku-only" gets one)
 *
 * Control plane:
 *   POST /__anthropic/script  {"replies": [...]} answers the next messages from this queue:
 *                             {"kind": "text", "text"}, {"kind": "json", "json"}, {"kind": "refusal",
 *                             "category", "explanation"}, {"kind": "max_tokens", "text"},
 *                             {"kind": "fallback", "text" or "json"}, {"kind": "error", "status",
 *                             "type", "retry_after"}
 *   GET  /__anthropic/log     every request received: method, path, version, beta, whether a key
 *                             came (never the key), the decoded body and the status answered
 *   POST /__anthropic/reset   forgets the queue, the log and the cache
 *
 * Without a script, a message is answered by a small deterministic stand-in of the model that
 * only uses the data it was given. It never calls anything.
 */

declare(strict_types=1);

namespace RitmoFakeApis;

require_once __DIR__ . '/Reply.php';

final class AnthropicApi
{
    public const VERSION = '2026-10-21';
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    public const REVOKED_KEY = 'sk-ant-revoked-key';
    public const MODELS = ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5'];
    public const FALLBACK_MODEL = 'claude-opus-4-8';

    private const FORBIDDEN_SCHEMA_KEYWORDS = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'minItems', 'maxItems', '$ref'];

    public function __construct(private readonly string $stateFile)
    {
    }

    /**
     * @param array<string, string> $headers names in lowercase
     */
    public function handle(string $method, string $path, array $headers, string $body): Reply
    {
        $path = (string) preg_replace('#^/anthropic(?=/)#', '', $path);
        if (str_starts_with($path, '/__anthropic/')) {
            return $this->control($method, $path, $body);
        }
        $decoded = json_decode($body, true);
        $reply = $this->answer($method, $path, $headers, is_array($decoded) ? $decoded : null, $body);
        $state = $this->state();
        $state['log'][] = [
            'method' => $method,
            'path' => $path,
            'version' => $headers['anthropic-version'] ?? null,
            'beta' => $headers['anthropic-beta'] ?? null,
            'has_key' => isset($headers['x-api-key']) && $headers['x-api-key'] !== '',
            'body' => is_array($decoded) ? $decoded : null,
            'status' => $reply->status,
        ];
        $this->save($state);

        return $reply;
    }

    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $body
     */
    private function answer(string $method, string $path, array $headers, ?array $body, string $raw): Reply
    {
        if (($headers['anthropic-version'] ?? '') !== self::VERSION) {
            return self::error(400, 'invalid_request_error', 'anthropic-version: header is required and must be a supported version.');
        }
        $key = $headers['x-api-key'] ?? '';
        if (!str_starts_with($key, 'sk-ant-') || $key === self::REVOKED_KEY) {
            return self::error(401, 'authentication_error', 'invalid x-api-key');
        }
        if ($method === 'GET' && $path === '/v1/models') {
            $ids = str_contains($key, 'haiku-only') ? ['claude-haiku-5-5'] : array_keys(self::MODELS);

            return Reply::json(200, [
                'data' => array_map(static fn (string $id): array => ['type' => 'model', 'id' => $id, 'display_name' => self::MODELS[$id], 'created_at' => '2026-10-01T00:00:00Z', 'max_input_tokens' => 1000000, 'max_tokens' => 128000], $ids),
                'has_more' => false,
                'first_id' => $ids[0],
                'last_id' => $ids[count($ids) - 1],
            ]);
        }
        if ($method !== 'POST' || $path !== '/v1/messages') {
            return self::error(404, 'not_found_error', 'Not found');
        }
        if ($body === null) {
            return self::error(400, 'invalid_request_error', 'The request body is not valid JSON.');
        }
        $invalid = self::invalid($body, $headers);
        if ($invalid !== null) {
            return self::error(400, 'invalid_request_error', $invalid);
        }
        if (!isset(self::MODELS[$body['model']])) {
            return self::error(404, 'not_found_error', 'model: ' . $body['model']);
        }

        $state = $this->state();
        $scripted = array_shift($state['queue']);
        $system = json_encode($body['system'] ?? null);
        $cached = in_array(md5((string) $system), $state['cache'], true);
        $state['cache'][] = md5((string) $system);
        $this->save($state);

        $reply = is_array($scripted) ? $scripted : $this->standIn($body);
        if (($reply['kind'] ?? '') === 'error') {
            $status = (int) ($reply['status'] ?? 500);

            return self::error($status, (string) ($reply['type'] ?? 'api_error'), 'Simulated failure.', isset($reply['retry_after']) ? ['retry-after' => (string) $reply['retry_after']] : []);
        }

        return Reply::json(200, self::message($body, $reply, $cached, strlen($raw)));
    }

    /**
     * Why the real API would refuse the body with a 400, or null.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private static function invalid(array $body, array $headers): ?string
    {
        foreach (['temperature', 'top_p', 'top_k'] as $sampling) {
            if (array_key_exists($sampling, $body)) {
                return $sampling . ': is not supported on this API version.';
            }
        }
        $thinking = $body['thinking'] ?? null;
        if (is_array($thinking) && (($thinking['type'] ?? '') === 'disabled' || isset($thinking['budget_tokens']))) {
            return 'thinking: this model does not support disabling thinking or a thinking budget.';
        }
        $choice = $body['tool_choice']['type'] ?? null;
        if (in_array($choice, ['any', 'tool'], true)) {
            return 'tool_choice: type "tool" and "any" are not supported for this model.';
        }
        if (!is_string($body['model'] ?? null) || !is_int($body['max_tokens'] ?? null) || $body['max_tokens'] < 1 || $body['max_tokens'] > 128000) {
            return 'model and max_tokens are required.';
        }
        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || $messages === [] || ($messages[count($messages) - 1]['role'] ?? '') !== 'user') {
            return 'messages: the last message must be a user message (prefill is not supported).';
        }
        if (array_key_exists('fallbacks', $body) && !str_contains($headers['anthropic-beta'] ?? '', self::FALLBACK_BETA)) {
            return 'fallbacks: requires the beta header ' . self::FALLBACK_BETA . '.';
        }
        $effort = $body['output_config']['effort'] ?? null;
        if ($effort !== null && !in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true)) {
            return 'output_config.effort: unknown value.';
        }
        $format = $body['output_config']['format'] ?? null;
        if ($format !== null) {
            if (($format['type'] ?? '') !== 'json_schema' || !is_array($format['schema'] ?? null)) {
                return 'output_config.format: type json_schema with a schema is required.';
            }
            $problem = self::schemaProblem($format['schema'], 'schema');
            if ($problem !== null) {
                return 'output_config.format.' . $problem;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaProblem(array $schema, string $path): ?string
    {
        foreach (self::FORBIDDEN_SCHEMA_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return $path . ': "' . $keyword . '" is not supported.';
            }
        }
        if (($schema['type'] ?? null) === 'object') {
            if (($schema['additionalProperties'] ?? null) !== false) {
                return $path . ': objects must set additionalProperties to false.';
            }
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                $problem = is_array($property) ? self::schemaProblem($property, $path . '.' . $name) : $path . '.' . $name . ': not a schema.';
                if ($problem !== null) {
                    return $problem;
                }
            }
        }
        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            return self::schemaProblem($schema['items'], $path . '[]');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $reply
     *
     * @return array<string, mixed>
     */
    private static function message(array $body, array $reply, bool $cached, int $inputBytes): array
    {
        $kind = (string) ($reply['kind'] ?? 'text');
        $text = array_key_exists('json', $reply) ? json_encode($reply['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) ($reply['text'] ?? '');
        $content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'simulated-signature']];
        $stop = 'end_turn';
        $details = null;
        $model = $body['model'];
        $usage = [
            'input_tokens' => max(1, intdiv($inputBytes, 8)),
            'output_tokens' => max(1, intdiv(strlen((string) $text), 4)),
            'cache_creation_input_tokens' => $cached ? 0 : max(1, intdiv(strlen((string) json_encode($body['system'] ?? '')), 4)),
            'cache_read_input_tokens' => $cached ? max(1, intdiv(strlen((string) json_encode($body['system'] ?? '')), 4)) : 0,
        ];
        if ($kind === 'refusal') {
            $content = [];
            $stop = 'refusal';
            $details = ['type' => 'refusal', 'category' => $reply['category'] ?? 'cyber', 'explanation' => $reply['explanation'] ?? 'The request was declined.'];
        } elseif ($kind === 'max_tokens') {
            $content[] = ['type' => 'text', 'text' => $text];
            $stop = 'max_tokens';
        } elseif ($kind === 'fallback') {
            $content = [['type' => 'fallback', 'from_model' => $model, 'reason' => 'refusal'], ['type' => 'text', 'text' => $text]];
            $model = self::FALLBACK_MODEL;
            $usage['iterations'] = [['type' => 'message', 'input_tokens' => 10, 'output_tokens' => 0], ['type' => 'fallback_message', 'input_tokens' => $usage['input_tokens'], 'output_tokens' => $usage['output_tokens']]];
        } else {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        return [
            'id' => 'msg_simulated_' . substr(md5($text . $stop), 0, 12),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content,
            'stop_reason' => $stop,
            'stop_sequence' => null,
            'stop_details' => $details,
            'usage' => $usage,
        ];
    }

    /**
     * The deterministic stand-in of the model: a report from the data's own facts, an alert
     * explanation from the alert's sentence, a draft from a few request shapes.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function standIn(array $body): array
    {
        $user = (string) ($body['messages'][count($body['messages']) - 1]['content'] ?? '');
        $portuguese = str_contains($user, 'Locale: pt_BR');
        $data = self::tag($user, 'data') ?? [];
        $properties = $body['output_config']['format']['schema']['properties'] ?? null;
        if (is_array($properties) && isset($properties['explanation'])) {
            $alert = is_array($data['alert'] ?? null) ? $data['alert'] : [];

            return ['kind' => 'json', 'json' => [
                'explanation' => ($portuguese ? 'O que aconteceu: ' : 'What happened: ') . (string) ($alert['summary'] ?? ''),
                'action' => $portuguese ? 'Abra a tela de Alertas e confira a noite.' : 'Open the Alerts screen and review the night.',
            ]];
        }
        if (is_array($properties) && isset($properties['kind'])) {
            return ['kind' => 'json', 'json' => self::draft($data, (string) (self::tag($user, 'request')['text'] ?? ''))];
        }
        $facts = array_slice(is_array($data['facts'] ?? null) ? $data['facts'] : [], 0, 3);
        if ($facts === []) {
            return ['kind' => 'text', 'text' => $portuguese ? 'Nada mudou desde o último relatório.' : 'Nothing changed since the last report.'];
        }
        $lines = array_map(static fn (array $f): string => $f['label'] . ': ' . $f['value'], $facts);

        return ['kind' => 'text', 'text' => ($portuguese ? 'Resumo da semana. ' : 'Summary of the week. ') . implode('. ', $lines) . '.'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function draft(array $data, string $request): array
    {
        $empty = ['type' => 'follow_up', 'rooms' => [], 'from' => '', 'to' => '', 'weekdays' => [], 'floor_amount' => 0, 'change_percent' => 0, 'percentile' => 0, 'note' => ''];
        $lower = mb_strtolower($request);
        $rooms = ['*'];
        foreach (is_array($data['rooms'] ?? null) ? $data['rooms'] : [] as $room) {
            if (is_array($room) && is_string($room['label'] ?? null) && $room['label'] !== '' && str_contains($lower, mb_strtolower($room['label']))) {
                $rooms = [(string) $room['code']];
            }
        }
        preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $lower, $percent);
        preg_match('/(?:r\$|\$)\s*(\d+(?:[.,]\d+)?)/', $lower, $amount);
        $today = (string) ($data['today'] ?? '2026-01-01');
        if ($percent !== [] && preg_match('/s[áa]bado|saturday/u', $lower) === 1 && preg_match('/dezembro|december/', $lower) === 1) {
            $year = (int) substr($today, 0, 4);

            return ['kind' => 'order', 'summary' => 'Raise the Saturdays of December by ' . $percent[1] . '%.', 'order' => ['type' => 'floor', 'rooms' => $rooms, 'from' => $year . '-12-01', 'to' => $year . '-12-31', 'weekdays' => [5], 'floor_amount' => 0, 'change_percent' => (float) str_replace(',', '.', $percent[1]), 'percentile' => 0, 'note' => 'Saturdays of December, raised as asked.'] + $empty, 'rule_changes' => [], 'questions' => []];
        }
        $period = is_array($data['periods'][0] ?? null) ? $data['periods'][0] : null;
        if ($amount !== [] && preg_match('/feriado|holiday/', $lower) === 1 && $period !== null) {
            return ['kind' => 'order', 'summary' => 'Do not sell below ' . $amount[1] . ' on ' . $period['label'] . '.', 'order' => ['type' => 'floor', 'rooms' => ['*'], 'from' => $period['first'], 'to' => $period['last'], 'weekdays' => [], 'floor_amount' => (float) str_replace(',', '.', $amount[1]), 'change_percent' => 0, 'percentile' => 0, 'note' => 'Minimum price for the holiday, as asked.'] + $empty, 'rule_changes' => [], 'questions' => []];
        }

        return ['kind' => 'not_supported', 'summary' => 'This request does not fit an order or a rule change.', 'order' => $empty, 'rule_changes' => [], 'questions' => ['Which nights and rooms do you mean?']];
    }

    /**
     * The JSON between <tag> and </tag> of a user message.
     *
     * @return array<string, mixed>|null
     */
    private static function tag(string $text, string $tag): ?array
    {
        if (preg_match('#<' . $tag . '>\n(.*?)\n</' . $tag . '>#s', $text, $match) !== 1) {
            return null;
        }
        $decoded = json_decode($match[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function control(string $method, string $path, string $body): Reply
    {
        $state = $this->state();
        if ($method === 'POST' && $path === '/__anthropic/reset') {
            $this->save(['queue' => [], 'log' => [], 'cache' => []]);

            return Reply::json(200, ['ok' => true]);
        }
        if ($method === 'POST' && $path === '/__anthropic/script') {
            $decoded = json_decode($body, true);
            $state['queue'] = [...$state['queue'], ...array_values(is_array($decoded['replies'] ?? null) ? $decoded['replies'] : [])];
            $this->save($state);

            return Reply::json(200, ['queued' => count($state['queue'])]);
        }
        if ($method === 'GET' && $path === '/__anthropic/log') {
            return Reply::json(200, ['requests' => $state['log']]);
        }

        return Reply::json(404, ['error' => 'unknown control route']);
    }

    /**
     * @param int                   $status
     * @param array<string, string> $headers
     */
    private static function error(int $status, string $type, string $message, array $headers = []): Reply
    {
        return Reply::json($status, ['type' => 'error', 'error' => ['type' => $type, 'message' => $message], 'request_id' => 'req_simulated'], $headers);
    }

    /**
     * @return array{queue: list<array<string, mixed>>, log: list<array<string, mixed>>, cache: list<string>}
     */
    private function state(): array
    {
        $raw = is_file($this->stateFile) ? (string) file_get_contents($this->stateFile) : '';
        $state = json_decode($raw, true);

        return [
            'queue' => array_values(is_array($state['queue'] ?? null) ? $state['queue'] : []),
            'log' => array_values(is_array($state['log'] ?? null) ? $state['log'] : []),
            'cache' => array_values(is_array($state['cache'] ?? null) ? $state['cache'] : []),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

if (PHP_SAPI === 'cli-server' && (get_included_files()[0] ?? '') === __FILE__) {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with((string) $name, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = (string) $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    (new AnthropicApi(getenv('RITMO_FAKE_ANTHROPIC_STATE') ?: sys_get_temp_dir() . '/ritmo-fake-anthropic.json'))->handle(
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        (string) (parse_url($uri, PHP_URL_PATH) ?: '/'),
        $headers,
        (string) file_get_contents('php://input')
    )->emit();
}
