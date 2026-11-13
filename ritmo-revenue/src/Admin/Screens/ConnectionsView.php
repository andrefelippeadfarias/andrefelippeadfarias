<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\NightDate;
use Ritmo\WordPress\ConnectorFactory;

/**
 * Settings › Connections in words (admin spec 5.13): what the plugin does in each system, whether
 * a key is saved (never a character of it), the other settings and the last test.
 */
final class ConnectionsView
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $services = ScreenServices::instance();
        $store = $services->services()->store();
        $vault = $services->services()->vault();
        $timezone = in_array(wp_timezone_string(), timezone_identifiers_list(), true) ? wp_timezone_string() : 'UTC';
        $providers = [];
        foreach (ConnectionActions::PROVIDERS as $provider => $secret) {
            $status = $vault->status($secret);
            $last = $services->connectionTests()->last($provider);
            $providers[$provider] = [
                'name' => ConnectionText::provider($provider),
                'does' => $provider === 'beds24'
                    ? __('Reads bookings and the calendar. Writes only availability, minimum and maximum stay, arrival and departure rules and channel limits; never a price, never a booking.', 'ritmo-revenue')
                    : __('Reads listings, prices and date rules. Writes floors, ceilings and stay rules of nights; listing values only with a person\'s approval.', 'ritmo-revenue'),
                'saved' => !$status->configured ? __('Not saved', 'ritmo-revenue') : ($status->updatedAt === null ? __('Saved', 'ritmo-revenue')
                    /* translators: %s: date and time */
                    : sprintf(__('Saved on %s', 'ritmo-revenue'), NightDate::instant($status->updatedAt, $timezone))),
                'configured' => $status->configured,
                'last' => $last === null ? __('Not tested yet.', 'ritmo-revenue') : sprintf(
                    /* translators: 1: date and time, 2: the result in words */
                    __('Last test %1$s: %2$s', 'ritmo-revenue'),
                    NightDate::instant($last['at'], $timezone),
                    ConnectionText::result($provider, $last['reason'], $last['counts'])
                ),
                'ok' => $last['ok'] ?? null,
            ];
        }

        return [
            'providers' => $providers,
            'token_kind' => (string) $store->get(ConnectorFactory::BEDS24_TOKEN_KIND, 'refresh'),
            'property_ids' => (string) $store->get(ConnectorFactory::BEDS24_PROPERTY_IDS, ''),
            'pms' => (string) $store->get(ConnectorFactory::PRICELABS_PMS, ConnectorFactory::DEFAULT_PRICELABS_PMS),
            'calendar_read' => $services->services()->connectors()->readsPricingCalendar(),
            'rest_url' => rest_url('ritmo/v1/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
        ];
    }
}
