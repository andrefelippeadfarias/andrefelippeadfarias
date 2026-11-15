<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Ai\Claude;

use Closure;
use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;

/**
 * The HTTP conversation with the Claude API: the headers, the meaning of each status and the
 * retries, inside the time the PHP request has left.
 *
 * - 400, 401, 402, 403, 404 and 413 are never repeated: a clear message says what to fix;
 * - 429 waits what retry-after asks when it is short and time allows, else it stops (RateLimited);
 * - 500 and 529 (and 503, 504) are repeated at most twice, after 2 and then 4 seconds (or the
 *   retry-after given), only when the wait and another full answer fit in the time left;
 * - a request that got no answer (timeout, connection refused) is not repeated: the model may have
 *   answered and been billed.
 *
 * The logs carry the status, the error type and the attempt; never the key, a prompt or an answer.
 */
final class ClaudeClient
{
    public const MAX_RETRIES = 2;

    public const RETRY_AFTER_MAX = 30;

    /** Seconds kept free at the end of the PHP request, whatever the API does. */
    private const MARGIN_SECONDS = 5;

    /** The shortest wait for an answer worth trying. */
    private const MIN_ATTEMPT_SECONDS = 10;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /** @var Closure(): ?float */
    private readonly Closure $timeLeft;

    /**
     * @param (Closure(float): void)|null $sleep    waits that many seconds (tests advance a clock)
     * @param (Closure(): ?float)|null    $timeLeft seconds the PHP request has left; null when unlimited
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ClaudeConfig $config,
        private readonly Logger $logger,
        ?Closure $sleep = null,
        ?Closure $timeLeft = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1000000));
            }
        };
        $this->timeLeft = $timeLeft ?? static fn (): ?float => null;
    }

    public function config(): ClaudeConfig
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, MessageBody::encode($body));
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    public function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    private function send(string $method, string $path, ?string $body): array
    {
        $operation = $method . ' ' . (string) preg_replace('/\?.*$/', '', $path);
        $url = $this->config->baseUrl . $path;
        $headers = MessageBody::headers($this->config, $body !== null);
        for ($attempt = 1; ; ++$attempt) {
            $timeout = $this->attemptTimeout(0.0);
            if ($timeout === null) {
                throw new ConnectorException(sprintf('Claude was not asked: the request has too little time left for an answer (%s).', $operation), ClaudeProvider::ID, null, true);
            }
            try {
                $response = $this->http->request($method, $url, $headers, $body, $timeout);
            } catch (ConnectorException $e) {
                $this->logger->warning('Claude request got no answer.', ['provider' => ClaudeProvider::ID, 'operation' => $operation, 'attempt' => $attempt]);

                throw $e;
            }
            $status = $response->status();
            $type = $response->isSuccess() ? null : ReplyParser::errorType($response->body());
            $this->logger->info('Claude request finished.', array_filter(['provider' => ClaudeProvider::ID, 'operation' => $operation, 'http_status' => $status, 'error_type' => $type, 'attempt' => $attempt], static fn (mixed $v): bool => $v !== null));
            if ($response->isSuccess()) {
                return $this->decode($response, $operation);
            }
            $wait = $this->retryWait($response, $attempt);
            if ($wait === null || $this->attemptTimeout($wait) === null) {
                throw self::failure($status, $type, $operation, self::retryAfter($response));
            }
            ($this->sleep)($wait);
        }
    }

    /**
     * The seconds to wait before the next attempt, or null when this answer is final.
     */
    private function retryWait(HttpResponse $response, int $attempt): ?float
    {
        $status = $response->status();
        if ($attempt > self::MAX_RETRIES) {
            return null;
        }
        $retryAfter = self::retryAfter($response);
        if ($status === 429) {
            return $retryAfter !== null && $retryAfter <= self::RETRY_AFTER_MAX ? (float) $retryAfter : null;
        }
        if (in_array($status, [500, 503, 504, 529], true)) {
            $growing = (float) (2 ** $attempt);

            return $retryAfter !== null && $retryAfter <= self::RETRY_AFTER_MAX ? max($growing, (float) $retryAfter) : $growing;
        }

        return null;
    }

    /**
     * The timeout of the next attempt after waiting $wait seconds, or null when it would not fit
     * in the time the PHP request has left.
     */
    private function attemptTimeout(float $wait): ?int
    {
        $left = ($this->timeLeft)();
        if ($left === null) {
            return $this->config->timeoutSeconds;
        }
        $available = (int) ($left - $wait - self::MARGIN_SECONDS);

        return $available < self::MIN_ATTEMPT_SECONDS ? null : min($this->config->timeoutSeconds, $available);
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException
     */
    private function decode(HttpResponse $response, string $operation): array
    {
        try {
            return $response->json();
        } catch (ConnectorException) {
            throw HttpFailure::unexpectedShape(ClaudeProvider::ID, ClaudeProvider::LABEL, $operation, 'the body is not JSON');
        }
    }

    private static function failure(int $status, ?string $type, string $operation, ?int $retryAfter): ConnectorException
    {
        $detail = sprintf('HTTP %d on %s%s', $status, $operation, $type === null ? '' : ', ' . $type);

        return match (true) {
            $status === 401 => new AuthFailed(sprintf('Claude refused the API key (%s). Check the key in the assistant settings.', $detail), ClaudeProvider::ID, 401),
            $status === 403 => new AuthFailed(sprintf('This API key may not use this feature or model (%s).', $detail), ClaudeProvider::ID, 403),
            $status === 402 => new ConnectorException(sprintf('The Claude account has a billing problem (%s). Check the plan and credits of the account.', $detail), ClaudeProvider::ID, 402, false),
            $status === 404 => new ConnectorException(sprintf('Claude does not know what was asked (%s). Check the model in the assistant settings.', $detail), ClaudeProvider::ID, 404, false),
            $status === 413 => new ConnectorException(sprintf('The request was too large for Claude (%s).', $detail), ClaudeProvider::ID, 413, false),
            $status === 400 => new ConnectorException(sprintf('Claude refused the request as invalid (%s).', $detail), ClaudeProvider::ID, 400, false),
            $status === 429 => new RateLimited(sprintf('Claude asked to slow down (%s). Try again later.', $detail), ClaudeProvider::ID, $retryAfter, 429),
            $status === 529 => new ConnectorException(sprintf('Claude is overloaded (%s). Try again in a few minutes.', $detail), ClaudeProvider::ID, 529, true),
            default => new ConnectorException(sprintf('Claude answered with an error (%s). Try again later.', $detail), ClaudeProvider::ID, $status, $status >= 500),
        };
    }

    private static function retryAfter(HttpResponse $response): ?int
    {
        $value = trim((string) $response->header('retry-after'));

        return preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : null;
    }
}
