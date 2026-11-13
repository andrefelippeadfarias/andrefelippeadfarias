<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Connectors\Support\Payload;
use Ritmo\Connectors\Support\Query;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;
use Ritmo\Contracts\WriteHttpClient;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Support\Clock;

/**
 * The HTTP conversation with the Beds24 API V2: authentication, the call credit, paging and the
 * meaning of each status. Reads are GET; the one write (the calendar) goes through post(), under a
 * write token, only when the HTTP client it was given can write.
 */
final class Beds24Client
{
    private const LABEL = 'Beds24';

    private const TIMEOUT_SECONDS = 60;

    /** An access token is not used during its last five minutes (or the last half of a shorter life). */
    private const TOKEN_MARGIN_SECONDS = 300;

    /** Far above any real answer; a page chain longer than this is a loop. */
    private const MAX_PAGES = 200;

    private const TOKEN_PATH = '/authentication/token';

    private readonly CreditWindow $credit;

    private ?string $accessToken = null;

    /** Unix time until which the access token is used; null when the API gave no lifetime. */
    private ?int $accessTokenGoodUntil = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Beds24Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
        $this->credit = new CreditWindow($clock, $config->minimumCredit);
    }

    /**
     * One GET, decoded. A rejected access token obtained from a refresh token is renewed and
     * the request repeated once.
     *
     * @param array<string, scalar|list<scalar>> $query
     *
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    public function get(string $path, array $query = []): array
    {
        $operation = 'GET ' . $path;
        $response = $this->send($path, $query, ['token' => $this->token()], $operation);

        if ($response->status() === 401 && $this->config->credentials->isRefreshable()) {
            $this->logger->warning('Beds24 rejected the access token; renewing it once.', ['provider' => Beds24Connector::ID, 'operation' => $operation]);
            $this->accessToken = null;
            $response = $this->send($path, $query, ['token' => $this->token()], $operation);
        }

        return $this->decode($response, $operation);
    }

    /**
     * Every item of "data" of a paged listing, following pages.nextPageExists.
     *
     * @param array<string, scalar|list<scalar>> $query
     *
     * @return list<array<int|string, mixed>>
     *
     * @throws ConnectorException
     */
    public function all(string $path, array $query = []): array
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $body = $this->get($path, $query + ['page' => $page]);
            if (!is_array($body['data'] ?? null)) {
                throw HttpFailure::unexpectedShape(Beds24Connector::ID, self::LABEL, 'GET ' . $path, 'there is no list of data');
            }

            array_push($items, ...Payload::rows($body, 'data'));

            $pages = $body['pages'] ?? null;
            if (!is_array($pages) || !Payload::flag($pages, 'nextPageExists')) {
                return $items;
            }
        }

        throw HttpFailure::unexpectedShape(Beds24Connector::ID, self::LABEL, 'GET ' . $path, sprintf('more than %d pages', self::MAX_PAGES));
    }

    /**
     * One write (a POST with a JSON body), sent once. A rejected access token obtained from a
     * refresh token is renewed and the same body sent once more (a 401 applies nothing); a second
     * 401 stops. The answer of the calendar write is a list with one result per room: any
     * "success": false is a refusal.
     *
     * @param list<array<string, mixed>> $body
     *
     * @return array<int|string, mixed>
     *
     * @throws WriteBlocked       when this client cannot write or the guard refuses the request
     * @throws ConnectorException on a failure status, a refusal in the answer or no answer
     */
    public function post(WriteToken $token, string $path, array $body): array
    {
        if (!$this->http instanceof WriteHttpClient) {
            throw WriteBlocked::forRequest('POST', $path, 'the Beds24 connector was built read-only');
        }
        $operation = 'POST ' . $path;
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $response = $this->sendWrite($this->http, $token, $path, $json, $operation);
        if ($response->status() === 401 && $this->config->credentials->isRefreshable()) {
            $this->logger->warning('Beds24 rejected the access token of a write; renewing it and sending the same body once.', ['provider' => Beds24Connector::ID, 'operation' => $operation]);
            $this->accessToken = null;
            $response = $this->sendWrite($this->http, $token, $path, $json, $operation);
        }

        $answer = $this->decode($response, $operation);
        foreach ($answer as $item) {
            if (is_array($item) && ($item['success'] ?? true) === false) {
                throw new ConnectorException(sprintf('Beds24 refused part of %s.', $operation), Beds24Connector::ID, $response->status(), false);
            }
        }

        return $answer;
    }

    /**
     * Credit left in the five-minute window according to the last response; null when unknown.
     */
    public function creditRemaining(): ?float
    {
        return $this->credit->remaining();
    }

    private function token(): string
    {
        $credentials = $this->config->credentials;
        if (!$credentials->isRefreshable()) {
            return $credentials->secret();
        }

        $expired = $this->accessTokenGoodUntil !== null && $this->clock->now()->getTimestamp() >= $this->accessTokenGoodUntil;
        if ($this->accessToken === null || $expired) {
            $this->exchangeRefreshToken();
        }

        return (string) $this->accessToken;
    }

    /**
     * Trades the refresh token for an access token (a GET: nothing changes in the account).
     *
     * @throws ConnectorException
     */
    private function exchangeRefreshToken(): void
    {
        $operation = 'GET ' . self::TOKEN_PATH;
        $response = $this->send(self::TOKEN_PATH, [], ['refreshToken' => $this->config->credentials->secret()], $operation);

        $status = $response->status();
        if ($status >= 400 && $status < 500 && $status !== 429 && $status !== 408) {
            throw new AuthFailed(sprintf('Beds24 did not accept the refresh token (HTTP %d on %s).', $status, $operation), Beds24Connector::ID, $status);
        }

        $body = $this->decode($response, $operation);
        $token = $body['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw HttpFailure::unexpectedShape(Beds24Connector::ID, self::LABEL, $operation, 'there is no access token');
        }

        // Without a lifetime the token is used until it is rejected: a lifetime is never assumed.
        $lifetime = Payload::positive($body, 'expiresIn');
        $this->accessToken = $token;
        $this->accessTokenGoodUntil = null;
        if ($lifetime !== null) {
            $seconds = (int) floor($lifetime);
            $margin = min(self::TOKEN_MARGIN_SECONDS, intdiv($seconds, 2));
            $this->accessTokenGoodUntil = $this->clock->now()->getTimestamp() + $seconds - $margin;
        }
    }

    /**
     * @param array<string, scalar|list<scalar>> $query
     * @param array<string, string>              $headers
     *
     * @throws ConnectorException
     */
    private function send(string $path, array $query, array $headers, string $operation): HttpResponse
    {
        $this->credit->assertEnough();

        $response = $this->http->request(
            'GET',
            Query::append($this->config->baseUrl . $path, $query),
            ['Accept' => 'application/json'] + $headers,
            null,
            self::TIMEOUT_SECONDS
        );

        $this->credit->note($response);
        $this->logger->debug('Beds24 request finished.', [
            'provider' => Beds24Connector::ID,
            'operation' => $operation,
            'http_status' => $response->status(),
        ]);

        return $response;
    }

    /**
     * @throws ConnectorException
     */
    private function sendWrite(WriteHttpClient $http, WriteToken $token, string $path, string $json, string $operation): HttpResponse
    {
        $this->credit->assertEnough();
        $response = $http->write(
            $token,
            'POST',
            $this->config->baseUrl . $path,
            ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'token' => $this->token()],
            $json,
            self::TIMEOUT_SECONDS
        );
        $this->credit->note($response);
        $this->logger->info('Beds24 write finished.', [
            'provider' => Beds24Connector::ID,
            'operation' => $operation,
            'http_status' => $response->status(),
        ]);

        return $response;
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
                Beds24Connector::ID,
                self::LABEL,
                $operation,
                $response->status(),
                CreditWindow::resetsIn($response)
            );
        }

        try {
            $body = $response->json();
        } catch (ConnectorException) {
            throw HttpFailure::unexpectedShape(Beds24Connector::ID, self::LABEL, $operation, 'the body is not JSON');
        }

        if (($body['success'] ?? true) === false) {
            throw new ConnectorException(sprintf('Beds24 reported a failure on %s.', $operation), Beds24Connector::ID, $response->status(), false);
        }

        return $body;
    }
}
