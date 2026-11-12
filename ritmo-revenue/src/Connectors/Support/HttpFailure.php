<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Support;

use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\RateLimited;

/**
 * Turns an HTTP status the API answered with into the typed exception of the contracts.
 *
 * Messages name the provider, the operation and the status only: never the response body,
 * which may quote what was sent.
 */
final class HttpFailure
{
    /**
     * @param string      $provider   connector id ("beds24")
     * @param string      $label      name of the system as shown to a person ("Beds24")
     * @param string      $operation  what was being read, e.g. "GET /bookings"
     * @param int|null    $retryAfter seconds until the limit resets, for a 429 that announces it
     * @param string|null $code       short machine code the API gave for the failure, when it is safe to show
     */
    public static function forStatus(
        string $provider,
        string $label,
        string $operation,
        int $status,
        ?int $retryAfter = null,
        ?string $code = null,
    ): ConnectorException {
        $detail = sprintf('HTTP %d on %s%s', $status, $operation, $code === null ? '' : ', code ' . $code);

        if ($status === 401 || $status === 403) {
            return new AuthFailed(sprintf('%s rejected the credentials (%s).', $label, $detail), $provider, $status);
        }
        if ($status === 429) {
            return new RateLimited(sprintf('%s rate limit reached (%s).', $label, $detail), $provider, $retryAfter, $status);
        }

        return new ConnectorException(
            sprintf('%s answered with an error (%s).', $label, $detail),
            $provider,
            $status,
            $status >= 500 || $status === 408
        );
    }

    /**
     * A response that arrived but does not have the documented shape.
     */
    public static function unexpectedShape(string $provider, string $label, string $operation, string $what): ConnectorException
    {
        return new ConnectorException(
            sprintf('%s answered %s outside the documented format: %s.', $label, $operation, $what),
            $provider,
            null,
            false
        );
    }

    /**
     * A machine code is shown only when it looks like one (API_KEY_INVALID), never free text.
     */
    public static function safeCode(mixed $candidate): ?string
    {
        return is_string($candidate) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $candidate) === 1 ? $candidate : null;
    }
}
