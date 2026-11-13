<?php

declare(strict_types=1);

namespace RitmoFakeApis;

/**
 * Both fake APIs behind one door, plus a small control plane for tests.
 *
 *   /beds24/v2/...      the PMS            (base URL for the Beds24 connector)
 *   /pricelabs/v1/...   the pricing system (base URL for the PriceLabs connector)
 *
 *   GET  /__log         every request received since the last reset: {"requests": [{method, path, query, status}]}
 *   POST /__reset       forgets the log, the access tokens issued and the failures asked for
 *   POST /__revoke      every access token issued so far stops working
 *   POST /__fault       {"method", "path", "status", "headers", "times", "mode", "seconds"}: the next
 *                       "times" requests to that method and full path answer with that status and
 *                       those headers (status 0 answers normally and only adds the headers). Modes:
 *                       "status" (default: nothing is applied), "apply_then_fail" (the write is
 *                       applied, then the status is answered with an empty body), "drop_silently"
 *                       (answered normally, nothing stored), "delay" (waits "seconds", then answers
 *                       normally: beyond the client's timeout the answer is lost)
 *   POST /__writes      {"not_ready": [listing ids]}: turns the writable world on (WriteWorld)
 *   GET  /__writes      what was written: date rules, PMS values, recalculations, write bodies
 *
 * Every request, control plane included, is written to the log before it is answered.
 */
final class Simulator
{
    private const BEDS24_PREFIX = '/beds24/v2';

    private const PRICELABS_PREFIX = '/pricelabs/v1';

    public function __construct(
        private readonly Scenario $scenario,
        private readonly Journal $journal,
    ) {
    }

    /**
     * @param array<string, string> $headers request headers, names in lowercase
     */
    public function handle(string $method, string $path, string $queryString, array $headers, string $body): Reply
    {
        $reply = $this->answer($method, $path, self::parseQuery($queryString), $headers, $body);
        $this->journal->record($method, $path, $queryString, $reply->status);

        return $reply;
    }

    /**
     * @param array<string, list<string>> $query
     * @param array<string, string>       $headers
     */
    private function answer(string $method, string $path, array $query, array $headers, string $body): Reply
    {
        if (str_starts_with($path, '/__')) {
            return $this->control($method, $path, $body);
        }

        $fault = $this->journal->takeFault($method, $path);
        $mode = $fault['mode'] ?? 'status';
        if ($fault !== null && $mode === 'status' && $fault['status'] > 0) {
            return Reply::json($fault['status'], ['success' => false, 'error' => 'Simulated failure.'], $fault['headers']);
        }
        if ($mode === 'delay') {
            sleep(max(0, (int) ($fault['seconds'] ?? 0)));
        }

        $world = $this->journal->writeWorld();
        $before = $world->snapshot();
        $reply = $this->route($method, $path, $query, $headers, $body, $world);
        if ($mode === 'drop_silently') {
            $world->restore($before);
            $world->logWrite($method, $path, json_decode($body, true));
        }
        if ($mode === 'apply_then_fail') {
            return new Reply($fault['status']);
        }

        return $fault === null ? $reply : $reply->withHeaders($fault['headers']);
    }

    /**
     * @param array<string, list<string>> $query
     * @param array<string, string>       $headers
     */
    private function route(string $method, string $path, array $query, array $headers, string $body, WriteWorld $world): Reply
    {
        $scenario = $world->enabled() ? $this->scenario->withWrites($world->overrides(), $world->calendar()) : $this->scenario;
        if (str_starts_with($path, self::BEDS24_PREFIX . '/')) {
            return (new Beds24Api($scenario, $this->journal, $world))->handle($method, substr($path, strlen(self::BEDS24_PREFIX)), $query, $headers, $body);
        }
        if (str_starts_with($path, self::PRICELABS_PREFIX . '/')) {
            return (new PriceLabsApi($scenario, $world))->handle($method, substr($path, strlen(self::PRICELABS_PREFIX)), $query, $headers, $body);
        }

        return Reply::json(404, ['error' => 'Not found.']);
    }

    private function control(string $method, string $path, string $body): Reply
    {
        if ($method === 'GET' && $path === '/__log') {
            return Reply::json(200, ['requests' => $this->journal->requests()]);
        }
        if ($method === 'POST' && $path === '/__reset') {
            $this->journal->reset();

            return Reply::json(200, ['ok' => true]);
        }
        if ($method === 'POST' && $path === '/__revoke') {
            $this->journal->revokeAccessTokens();

            return Reply::json(200, ['ok' => true]);
        }
        if ($path === '/__writes') {
            return $this->writes($method, $body);
        }
        if ($method === 'POST' && $path === '/__fault') {
            $fault = json_decode($body, true);
            if (!is_array($fault) || !is_string($fault['method'] ?? null) || !is_string($fault['path'] ?? null) || !is_int($fault['status'] ?? null)) {
                return Reply::json(400, ['error' => 'A fault needs a method, a path and a status.']);
            }
            $this->journal->addFault([
                'method' => strtoupper($fault['method']),
                'path' => $fault['path'],
                'status' => $fault['status'],
                'headers' => array_map('strval', is_array($fault['headers'] ?? null) ? $fault['headers'] : []),
                'times' => max(1, (int) ($fault['times'] ?? 1)),
                'mode' => in_array($fault['mode'] ?? 'status', ['status', 'apply_then_fail', 'drop_silently', 'delay'], true) ? (string) ($fault['mode'] ?? 'status') : 'status',
                'seconds' => max(0, (int) ($fault['seconds'] ?? 0)),
            ]);

            return Reply::json(200, ['ok' => true]);
        }

        return Reply::json(404, ['error' => 'Unknown control request.']);
    }

    private function writes(string $method, string $body): Reply
    {
        $world = $this->journal->writeWorld();
        if ($method === 'GET') {
            return Reply::json(200, $world->dump());
        }
        $options = json_decode($body, true);
        $overrides = [];
        foreach ($this->scenario->rooms() as $room) {
            $overrides[$this->scenario->listingId($room)] = $this->scenario->overrides($this->scenario->listingId($room));
        }
        $world->enable($overrides, array_map('strval', is_array($options) ? ($options['not_ready'] ?? []) : []));

        return Reply::json(200, ['ok' => true]);
    }

    /**
     * A query string where a repeated name is a list (status=a&status=b).
     *
     * @return array<string, list<string>>
     */
    private static function parseQuery(string $queryString): array
    {
        $query = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[urldecode($name)][] = urldecode($value);
        }

        return $query;
    }
}
