<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Connectors\Support\Query;
use Ritmo\Connectors\Support\RequestBudget;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;

/**
 * The HTTP conversation with the PriceLabs Customer API: the key header, the hourly budget and
 * the meaning of each status.
 *
 * It can send a GET to any path of the API and one POST, to the calendar read
 * (PriceLabsConnector::PRICES_PATH), which changes nothing in the account. There is no other
 * way to send a request through this class.
 */
final class PriceLabsClient
{
    private const LABEL = 'PriceLabs';

    private const TIMEOUT_SECONDS = 120;

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
