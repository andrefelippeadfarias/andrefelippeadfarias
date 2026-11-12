<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Support;

use InvalidArgumentException;

/**
 * Chooses the base URL a connector talks to.
 *
 * The production URL of every connector is a constant. It can be replaced (to point a
 * connector at the local simulator) only while the constant RITMO_DEV is exactly true, so a
 * stored setting can never redirect the credentials of a live site somewhere else.
 */
final class BaseUrl
{
    public const DEV_CONSTANT = 'RITMO_DEV';

    /**
     * @param string      $production the connector's fixed base URL
     * @param string|null $override   replacement asked for by the configuration; null or '' for none
     *
     * @throws InvalidArgumentException when an override is given outside development mode or is not a plain http(s) URL
     */
    public static function resolve(string $production, ?string $override): string
    {
        return self::choose($production, $override, self::devMode());
    }

    /**
     * The decision of resolve() with the development flag given by the caller.
     *
     * @throws InvalidArgumentException as resolve()
     */
    public static function choose(string $production, ?string $override, bool $devMode): string
    {
        if ($override === null || $override === '') {
            return $production;
        }

        $override = rtrim($override, '/');
        if ($override === $production) {
            return $production;
        }
        if (!$devMode) {
            throw new InvalidArgumentException('The base URL of a connector can only be replaced while RITMO_DEV is true.');
        }
        if (!self::isPlainHttpUrl($override)) {
            throw new InvalidArgumentException('A replacement base URL must be an http(s) URL without credentials, query or fragment.');
        }

        return $override;
    }

    public static function devMode(): bool
    {
        return defined(self::DEV_CONSTANT) && constant(self::DEV_CONSTANT) === true;
    }

    private static function isPlainHttpUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }
}
