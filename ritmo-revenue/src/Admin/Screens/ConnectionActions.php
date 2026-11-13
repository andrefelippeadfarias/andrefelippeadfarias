<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ConfirmTyped;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Notice;
use Ritmo\WordPress\ConnectorFactory;

/**
 * The forms of Settings › Connections: test a connection (one test per provider every 30
 * seconds; the result in words) and forget a saved key (typed provider name). Saving keys keeps
 * the M1 action "ritmo_save_connections".
 */
final class ConnectionActions
{
    public const PROVIDERS = ['beds24' => ConnectorFactory::BEDS24_SECRET, 'pricelabs' => ConnectorFactory::PRICELABS_SECRET];

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [
            new FormAction('ritmo_connection_test', Capability::MANAGE_CONNECTIONS, Menu::SETTINGS, self::test(...)),
            new FormAction('ritmo_forget_secret', Capability::MANAGE_CONNECTIONS, Menu::SETTINGS, self::forget(...)),
        ];
    }

    /**
     * Runs the test (or answers the recent one within the cooldown).
     *
     * @return array{ok: bool, reason: string, text: string, tested_at: string, cached: bool}
     */
    public static function run(string $provider): array
    {
        $services = ScreenServices::instance();
        $tests = $services->connectionTests();
        $recent = $tests->withinCooldown($provider);
        $result = $recent ?? $tests->record($provider, $services->services()->connectors()->test($provider), get_current_user_id());
        $text = ConnectionText::result($provider, $result['reason'], $result['counts']);
        if ($recent !== null) {
            /* translators: 1: seconds, 2: the result of that test */
            $text = sprintf(__('Tested %1$s seconds ago: %2$s', 'ritmo-revenue'), number_format_i18n(max(0, $services->services()->clock()->now()->getTimestamp() - $recent['at']->getTimestamp())), $text);
        }

        return ['ok' => $result['ok'], 'reason' => $result['reason'], 'text' => $text, 'tested_at' => $result['at']->format(DATE_ATOM), 'cached' => $recent !== null];
    }

    /**
     * @return array<string, string>
     */
    private static function test(Input $form): array
    {
        $provider = $form->choice('provider', array_keys(self::PROVIDERS), 'beds24');
        $result = self::run($provider);
        Notice::flash($result['ok'] ? Notice::SUCCESS : Notice::ERROR, $result['text']);

        return ['tab' => $form->choice('tab', ['connections', 'setup'], 'connections'), 'step' => $form->text('step', 2)];
    }

    /**
     * @return array<string, string>
     */
    private static function forget(Input $form): array
    {
        $provider = $form->choice('provider', array_keys(self::PROVIDERS), '');
        if ($provider === '') {
            return ['tab' => 'connections'];
        }
        ConfirmTyped::check(ConnectionText::provider($provider), $form->text('confirmation', 32));
        ScreenServices::instance()->services()->vault()->forget(self::PROVIDERS[$provider]);
        /* translators: %s: Beds24 or PriceLabs */
        Notice::flash(Notice::SUCCESS, sprintf(__('The key of %s was forgotten. The plugin cannot read from it until a new key is saved.', 'ritmo-revenue'), ConnectionText::provider($provider)));

        return ['tab' => 'connections'];
    }
}
