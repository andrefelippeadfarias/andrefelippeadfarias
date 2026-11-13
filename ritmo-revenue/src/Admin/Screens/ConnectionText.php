<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Application\Dashboard\Connections\ConnectionReason;

/**
 * The result of a connection test in one sentence by its reason (admin spec 5.13): never the
 * connector's own sentence, an HTTP status or a response body.
 */
final class ConnectionText
{
    private function __construct()
    {
    }

    public static function provider(string $provider): string
    {
        return $provider === 'pricelabs' ? 'PriceLabs' : 'Beds24';
    }

    /**
     * @param array<string, int|string> $counts
     */
    public static function result(string $provider, string $reason, array $counts = []): string
    {
        $name = self::provider($provider);

        return match ($reason) {
            ConnectionReason::OK => self::ok($provider, $counts),
            /* translators: %s: Beds24 or PriceLabs */
            ConnectionReason::AUTH => sprintf(__('%1$s refused the key. Create a new key in %1$s and paste it here.', 'ritmo-revenue'), $name),
            /* translators: %s: Beds24 or PriceLabs */
            ConnectionReason::FORBIDDEN => sprintf(__('%s accepted the key but does not allow this access. Check the permissions of the key.', 'ritmo-revenue'), $name),
            /* translators: %s: Beds24 or PriceLabs */
            ConnectionReason::NETWORK => sprintf(__('Could not reach %s. Try again in a few minutes.', 'ritmo-revenue'), $name),
            /* translators: %s: Beds24 or PriceLabs */
            ConnectionReason::RATE_LIMITED => sprintf(__('%s asked to wait. Try again in a minute.', 'ritmo-revenue'), $name),
            /* translators: %s: Beds24 or PriceLabs */
            ConnectionReason::NOT_FOUND => sprintf(__('%s did not find what the plugin asked for. Check the ids typed here.', 'ritmo-revenue'), $name),
            ConnectionReason::NO_PROPERTIES => __('The key works but sees no property: check the property ids.', 'ritmo-revenue'),
            ConnectionReason::NO_LISTINGS => __('The key works but the account has no listing.', 'ritmo-revenue'),
            ConnectionReason::PMS_MISMATCH => sprintf(
                /* translators: 1: number of listings, 2: name of the PMS in the pricing system */
                __('%1$s listings found; none belongs to the PMS named "%2$s".', 'ritmo-revenue'),
                number_format_i18n((int) ($counts['account_listings'] ?? 0)),
                (string) ($counts['pms'] ?? '')
            ),
            /* translators: %s: Beds24 or PriceLabs */
            ConnectionReason::NOT_CONFIGURED => sprintf(__('No key of %s is saved yet.', 'ritmo-revenue'), $name),
            default => __('The test failed. Nothing was changed.', 'ritmo-revenue'),
        };
    }

    /**
     * @param array<string, int|string> $counts
     */
    private static function ok(string $provider, array $counts): string
    {
        if ($provider === 'pricelabs') {
            return sprintf(
                /* translators: 1: listings of the PMS, 2: listings in the account */
                __('Connected to PriceLabs: %1$s of %2$s listings belong to your PMS.', 'ritmo-revenue'),
                number_format_i18n((int) ($counts['listings'] ?? 0)),
                number_format_i18n((int) ($counts['account_listings'] ?? 0))
            );
        }
        $properties = (int) ($counts['properties'] ?? 0);

        /* translators: %s: number of properties */
        return sprintf(_n('Connected to Beds24: %s property visible.', 'Connected to Beds24: %s properties visible.', $properties, 'ritmo-revenue'), number_format_i18n($properties));
    }
}
