<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Diagnostics;

/**
 * The support file (admin spec 5.14, decision D28): one JSON document whose top-level keys are
 * exactly the allowlist, built from facts the caller gathered. Secrets, bookings, user names,
 * e-mail addresses and alert facts never enter it: settings are filtered by key, every text is
 * scrubbed of e-mail addresses, and anything else is dropped. Pure.
 */
final class SupportBundle
{
    public const KEYS = ['generated_at', 'versions', 'environment', 'active_plugins', 'settings', 'rules', 'properties', 'room_types', 'schedule', 'runs', 'alerts', 'log', 'migrations'];

    /** Setting keys whose value never leaves the site (secrets, recipients, the vault). */
    private const SECRET_SETTINGS = '/(secret|token|key|password|vault|recipient|email|mail|nonce|salt)/i';

    private const EMAIL = '/[^\s@"]+@[^\s@"]+\.[^\s@"]+/';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $facts the parts, keyed like KEYS; unknown keys are dropped
     *
     * @return array<string, mixed>
     */
    public static function build(array $facts): array
    {
        $bundle = [];
        foreach (self::KEYS as $key) {
            $value = $facts[$key] ?? [];
            $bundle[$key] = $key === 'settings' && is_array($value) ? self::settings(self::scrub($value)) : self::scrub($value);
        }

        return $bundle;
    }

    /**
     * The plugin's settings without secrets and recipients: a secret is replaced by whether it is
     * saved.
     *
     * @param array<mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function settings(array $settings): array
    {
        $out = [];
        foreach ($settings as $name => $value) {
            $out[(string) $name] = preg_match(self::SECRET_SETTINGS, (string) $name) === 1 ? ($value === null || $value === '' || $value === [] ? 'not saved' : 'saved') : $value;
        }

        return $out;
    }

    private static function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::scrub($item);
            }

            return $out;
        }
        if (is_string($value)) {
            return (string) preg_replace(self::EMAIL, '[e-mail removed]', $value);
        }

        return is_int($value) || is_float($value) || is_bool($value) || $value === null ? $value : null;
    }
}
