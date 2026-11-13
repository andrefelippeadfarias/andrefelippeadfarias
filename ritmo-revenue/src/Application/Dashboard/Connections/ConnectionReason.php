<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Connections;

use Ritmo\Contracts\ConnectionStatus;

/**
 * Why a connection test ended as it did, as one of a closed list of reasons the screen
 * translates (admin spec 5.13): the connector's own "reason" when it gives one, else derived from
 * the HTTP status and the counts. Never the connector's sentence or a response body. Pure.
 */
final class ConnectionReason
{
    public const OK = 'ok';
    public const AUTH = 'auth';
    public const FORBIDDEN = 'forbidden';
    public const NETWORK = 'network';
    public const RATE_LIMITED = 'rate_limited';
    public const NOT_FOUND = 'not_found';
    public const NO_PROPERTIES = 'no_properties';
    public const NO_LISTINGS = 'no_listings';
    public const PMS_MISMATCH = 'pms_mismatch';
    public const NOT_CONFIGURED = 'not_configured';
    public const UNKNOWN = 'unknown';

    public const ALL = [self::OK, self::AUTH, self::FORBIDDEN, self::NETWORK, self::RATE_LIMITED, self::NOT_FOUND, self::NO_PROPERTIES, self::NO_LISTINGS, self::PMS_MISMATCH, self::NOT_CONFIGURED, self::UNKNOWN];

    private function __construct()
    {
    }

    public static function of(ConnectionStatus $status): string
    {
        $given = $status->details['reason'] ?? null;
        if (is_string($given) && in_array($given, self::ALL, true)) {
            return $given;
        }
        if ($status->ok) {
            return self::okReason($status->details);
        }
        $http = $status->details['http_status'] ?? null;

        return match (true) {
            $http === 401 => self::AUTH,
            $http === 403 => self::FORBIDDEN,
            $http === 404 => self::NOT_FOUND,
            $http === 429 => self::RATE_LIMITED,
            is_int($http) => self::UNKNOWN,
            str_contains(strtolower($status->message), 'not configured') || str_contains(strtolower($status->message), 'no key') => self::NOT_CONFIGURED,
            default => self::NETWORK,
        };
    }

    /**
     * The counts a successful test reported, kept for the sentence ("3 properties visible").
     *
     * @return array<string, int|string>
     */
    public static function counts(ConnectionStatus $status): array
    {
        return array_filter(
            array_intersect_key($status->details, array_flip(['properties', 'listings', 'account_listings', 'pms'])),
            static fn (mixed $v): bool => is_int($v) || (is_string($v) && preg_match('/^[a-z0-9_.-]{1,40}$/', $v) === 1),
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function okReason(array $details): string
    {
        if (($details['properties'] ?? null) === 0) {
            return self::NO_PROPERTIES;
        }
        if (($details['account_listings'] ?? null) === 0) {
            return self::NO_LISTINGS;
        }
        if (($details['listings'] ?? null) === 0) {
            return self::PMS_MISMATCH;
        }

        return self::OK;
    }
}
