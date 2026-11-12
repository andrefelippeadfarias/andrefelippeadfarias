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
 *   POST /__fault       {"method", "path", "status", "headers", "times"}: the next "times" requests
 *                       to that method and full path answer with that status and those headers;
 *                       status 0 answers normally and only adds the headers
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
        if ($fault !== null && $fault['status'] > 0) {
            return Reply::json($fault['status'], ['success' => false, 'error' => 'Simulated failure.'], $fault['headers']);
        }

        if (str_starts_with($path, self::BEDS24_PREFIX . '/')) {
            $reply = (new Beds24Api($this->scenario, $this->journal))->handle($method, substr($path, strlen(self::BEDS24_PREFIX)), $query, $headers);
        } elseif (str_starts_with($path, self::PRICELABS_PREFIX . '/')) {
            $reply = (new PriceLabsApi($this->scenario))->handle($method, substr($path, strlen(self::PRICELABS_PREFIX)), $query, $headers, $body);
        } else {
            $reply = Reply::json(404, ['error' => 'Not found.']);
        }

        return $fault === null ? $reply : $reply->withHeaders($fault['headers']);
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
            ]);

            return Reply::json(200, ['ok' => true]);
        }

        return Reply::json(404, ['error' => 'Unknown control request.']);
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
