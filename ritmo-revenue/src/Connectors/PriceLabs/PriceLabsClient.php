<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Connectors\Support\Query;
use Ritmo\Connectors\Support\RequestBudget;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotReady;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;
use Ritmo\Contracts\WriteHttpClient;
use Ritmo\Contracts\WriteToken;

/**
 * The HTTP conversation with the PriceLabs Customer API: the key header, the hourly budget and
 * the meaning of each status.
 *
 * It can send a GET to any path of the API and one POST, to the calendar read
 * (PriceLabsConnector::PRICES_PATH), which changes nothing in the account. Writes go only
 * through write(), under a write token, and only when the HTTP client it was given can write.
 */
final class PriceLabsClient
{
    private const LABEL = 'PriceLabs';

    private const TIMEOUT_SECONDS = 120;

    /** What the API answers (400) for a date it has not priced yet; matched without case. */
    private const NOT_READY_PATTERN = '/(isn\'t|is not|not) available yet/i';

    public function __construct(
        private readonly HttpClient $http,
        private readonly PriceLabsConfig $config,
        private readonly RequestBudget $budget,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, scalar|list<scalar>> $query
     *
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, Query::append($this->config->baseUrl . $path, $query), null);
    }

    /**
     * The calendar read of the API, which takes its parameters in the body of a POST.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    public function readCalendar(array $parameters): array
    {
        $path = PriceLabsConnector::PRICES_PATH;

        return $this->send('POST', $path, $this->config->baseUrl . $path, json_encode($parameters, JSON_THROW_ON_ERROR));
    }

    /**
     * One write, sent once (never repeated here: a lost answer leaves the outcome unknown and the
     * caller re-reads before anything is sent again).
     *
     * @param array<string, mixed> $body
     *
     * @return array<int|string, mixed> the decoded answer; empty for an empty answer (204)
     *
     * @throws WriteBlocked       when this client cannot write or the guard refuses the request
     * @throws NotReady           on the 400 of a date the pricing system has not priced yet
     * @throws ConnectorException on any other failure (status, timeout)
     */
    public function write(WriteToken $token, string $method, string $path, array $body): array
    {
        if (!$this->http instanceof WriteHttpClient) {
            throw WriteBlocked::forRequest($method, $path, 'the PriceLabs connector was built read-only');
        }
        $operation = $method . ' ' . $path;
        $headers = ['X-API-Key' => $this->config->apiKey(), 'Accept' => 'application/json', 'Content-Type' => 'application/json'];

        $this->budget->assertOpen();
        try {
            $response = $this->http->write($token, $method, $this->config->baseUrl . $path, $headers, json_encode($body, JSON_THROW_ON_ERROR), self::TIMEOUT_SECONDS);
        } catch (ConnectorException $e) {
            $this->budget->record(false);

            throw $e;
        }
        $this->budget->record($response->isSuccess());
        $this->logger->info('PriceLabs write finished.', [
            'provider' => PriceLabsConnector::ID,
            'operation' => $operation,
            'http_status' => $response->status(),
        ]);

        if ($response->status() === 400 && preg_match(self::NOT_READY_PATTERN, $response->body()) === 1) {
            throw new NotReady(sprintf('PriceLabs has no pricing data yet for a date of %s (HTTP 400).', $operation), PriceLabsConnector::ID, 400);
        }
        if ($response->isSuccess() && trim($response->body()) === '') {
            return [];
        }

        return $this->decode($response, $operation);
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    private function send(string $method, string $path, string $url, ?string $body): array
    {
        $operation = $method . ' ' . $path;
        $headers = ['X-API-Key' => $this->config->apiKey(), 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $this->budget->assertOpen();
        try {
            $response = $this->http->request($method, $url, $headers, $body, self::TIMEOUT_SECONDS);
        } catch (ConnectorException $e) {
            // Sent and lost: the API may have counted it, so the budget does too.
            $this->budget->record(false);

            throw $e;
        }
        $this->budget->record($response->isSuccess());

        $this->logger->debug('PriceLabs request finished.', [
            'provider' => PriceLabsConnector::ID,
            'operation' => $operation,
            'http_status' => $response->status(),
        ]);

        return $this->decode($response, $operation);
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    private function decode(HttpResponse $response, string $operation): array
    {
        if (!$response->isSuccess()) {
            throw HttpFailure::forStatus(
                PriceLabsConnector::ID,
                self::LABEL,
                $operation,
                $response->status(),
                self::retryAfter($response),
                self::errorCode($response)
            );
        }

        try {
            return $response->json();
        } catch (ConnectorException) {
            throw HttpFailure::unexpectedShape(PriceLabsConnector::ID, self::LABEL, $operation, 'the body is not JSON');
        }
    }

    private static function retryAfter(HttpResponse $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));

        return preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * The machine code of an error body (API_KEY_INVALID), wherever the API put it.
     */
    private static function errorCode(HttpResponse $response): ?string
    {
        try {
            $body = $response->json();
        } catch (ConnectorException) {
            return null;
        }

        $error = $body['error'] ?? null;
        $candidates = [is_array($error) ? ($error['code'] ?? null) : $error, $body['code'] ?? null, $body['error_status'] ?? null];
        foreach ($candidates as $candidate) {
            $code = HttpFailure::safeCode($candidate);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }
}
