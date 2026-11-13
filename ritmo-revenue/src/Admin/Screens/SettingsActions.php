<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use InvalidArgumentException;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Notice;
use Ritmo\WordPress\ConnectorFactory;
use Ritmo\WordPress\Uninstaller;

/**
 * The forms of Settings (admin spec 5.13, 5.16): save the connections (a key typed replaces the
 * saved one; an empty field keeps it) and the choice of what uninstalling removes.
 */
final class SettingsActions
{
    private const MAX_SECRET = 2048;

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        $c = Capability::MANAGE_CONNECTIONS;

        return [
            new FormAction('ritmo_save_connections', $c, Menu::SETTINGS, self::saveConnections(...)),
            new FormAction('ritmo_save_settings', $c, Menu::SETTINGS, self::saveSettings(...)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function saveConnections(Input $form): array
    {
        $services = ScreenServices::instance()->services();
        $typed = $form->text('beds24_property_ids', 400);
        $ids = ConnectorFactory::propertyIds($typed);
        if ($typed !== '' && count($ids) !== count(array_filter(array_map('trim', explode(',', $typed)), 'strlen'))) {
            throw new InvalidArgumentException(__('The property ids in the PMS are whole numbers separated by commas.', 'ritmo-revenue'));
        }
        $pms = strtolower($form->text('pricelabs_pms', 40));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $pms) !== 1) {
            throw new InvalidArgumentException(__('The name of your PMS is the short name the pricing system uses for it, such as "beds24".', 'ritmo-revenue'));
        }
        $secrets = [ConnectorFactory::BEDS24_SECRET => $form->secret('beds24_token'), ConnectorFactory::PRICELABS_SECRET => $form->secret('pricelabs_api_key')];
        foreach ($secrets as $value) {
            if ($value !== '' && (preg_match('/[\x00-\x20\x7f]/', $value) === 1 || strlen($value) > self::MAX_SECRET)) {
                throw new InvalidArgumentException(__('A key or token cannot contain spaces or control characters.', 'ritmo-revenue'));
            }
        }
        foreach ($secrets as $name => $value) {
            if ($value !== '') {
                $services->vault()->put($name, $value);
            }
        }
        $services->store()->set(ConnectorFactory::BEDS24_TOKEN_KIND, $form->choice('beds24_token_kind', ['refresh', 'long_life'], 'refresh'));
        $services->store()->set(ConnectorFactory::BEDS24_PROPERTY_IDS, implode(',', $ids));
        $services->store()->set(ConnectorFactory::PRICELABS_PMS, $pms);
        Notice::flash(Notice::SUCCESS, __('Connections saved. Test them to check the keys.', 'ritmo-revenue'));

        return ['tab' => 'connections'];
    }

    /**
     * @return array<string, string>
     */
    private static function saveSettings(Input $form): array
    {
        ScreenServices::instance()->services()->store()->set(Uninstaller::DELETE_DATA, $form->bool('delete_data'));
        Notice::flash(Notice::SUCCESS, __('Settings saved.', 'ritmo-revenue'));

        return ['tab' => 'uninstall'];
    }
}
