<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ConfirmTyped;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Ratio;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Admin\Notice;
use Ritmo\WordPress\ConnectorFactory;

/**
 * The forms of the setup wizard (admin spec 5.1): each step checks its own capability (the PMS
 * and pricing keys: connections; property, rooms and rules: rules; the safety limits: safety, with
 * the property id typed). Every step changes only local data; nothing is written outside.
 */
final class WizardActions
{
    private const PRESET_MAX_BYTES = 1048576;

    private const ROOM_FIELDS = ['code', 'name', 'units', 'pms_ref', 'pricing_ref', 'base', 'min', 'max', 'tags', 'skip'];

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [
            new FormAction('ritmo_wizard_save_pms', Capability::MANAGE_CONNECTIONS, Menu::SETTINGS, self::savePms(...)),
            new FormAction('ritmo_wizard_save_pricing', Capability::MANAGE_CONNECTIONS, Menu::SETTINGS, self::savePricing(...)),
            new FormAction('ritmo_wizard_save_property', Capability::MANAGE_RULES, Menu::SETTINGS, self::saveProperty(...)),
            new FormAction('ritmo_wizard_save_rooms', Capability::MANAGE_RULES, Menu::SETTINGS, self::saveRooms(...)),
            new FormAction('ritmo_wizard_save_rules', Capability::MANAGE_RULES, Menu::SETTINGS, self::saveRules(...)),
            new FormAction('ritmo_wizard_confirm_safety', Capability::MANAGE_SAFETY, Menu::SETTINGS, self::confirmSafety(...)),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function savePms(Input $form): array
    {
        $services = ScreenServices::instance()->services();
        $ids = ConnectorFactory::propertyIds($form->text('beds24_property_ids', 400));
        $services->store()->set(ConnectorFactory::BEDS24_TOKEN_KIND, $form->choice('beds24_token_kind', ['refresh', 'long_life'], 'refresh'));
        $services->store()->set(ConnectorFactory::BEDS24_PROPERTY_IDS, implode(',', $ids));

        return self::keyAndTest($form->secret('beds24_token'), ConnectorFactory::BEDS24_SECRET, 'beds24', 2);
    }

    /**
     * @return array<string, string|int>
     */
    private static function savePricing(Input $form): array
    {
        $pms = strtolower($form->text('pricelabs_pms', 40));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $pms) !== 1) {
            throw new UserFacing(__('The PMS name is the short name the pricing system uses for your PMS, such as "beds24".', 'ritmo-revenue'));
        }
        ScreenServices::instance()->services()->store()->set(ConnectorFactory::PRICELABS_PMS, $pms);

        return self::keyAndTest($form->secret('pricelabs_api_key'), ConnectorFactory::PRICELABS_SECRET, 'pricelabs', 3);
    }

    /**
     * @return array<string, string|int>
     */
    private static function keyAndTest(string $secret, string $name, string $provider, int $step): array
    {
        $services = ScreenServices::instance();
        if ($secret !== '') {
            if (preg_match('/[\x00-\x20\x7f]/', $secret) === 1 || strlen($secret) > 2048) {
                throw new UserFacing(__('A key cannot contain spaces or control characters.', 'ritmo-revenue'));
            }
            $services->services()->vault()->put($name, $secret);
            $result = $services->connectionTests()->record($provider, $services->services()->connectors()->test($provider), get_current_user_id());
            $text = ConnectionText::result($provider, $result['reason'], $result['counts']);
            $ok = $result['ok'];
        } else {
            $result = ConnectionActions::run($provider);
            $text = $result['text'];
            $ok = $result['ok'];
        }
        Notice::flash($ok ? Notice::SUCCESS : Notice::ERROR, $text);

        return ['tab' => 'setup', 'step' => $ok ? $step + 1 : $step];
    }

    /**
     * @return array<string, string|int>
     */
    private static function saveProperty(Input $form): array
    {
        $property = ScreenServices::instance()->services()->setup()->createProperty([
            'id' => $form->text('id', 32),
            'name' => $form->text('name', 100),
            'timezone' => $form->text('timezone', 64),
            'currency' => strtoupper($form->text('currency', 3)),
            'tier' => $form->text('tier', 32) === '' ? 'standard' : $form->text('tier', 32),
        ]);
        /* translators: %s: property name */
        Notice::flash(Notice::SUCCESS, sprintf(__('Property "%s" created in shadow mode.', 'ritmo-revenue'), $property->name));

        return ['tab' => 'setup', 'step' => 5, 'property' => $property->id];
    }

    /**
     * @return array<string, string|int>
     */
    private static function saveRooms(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $rows = [];
        foreach ($form->rows('rooms', self::ROOM_FIELDS) as $row) {
            if ($row['skip'] === '1' || ($row['code'] === '' && $row['pms_ref'] === '' && $row['name'] === '')) {
                continue;
            }
            $rows[] = [
                'property_id' => $property,
                'code' => $row['code'],
                'name' => $row['name'],
                'units' => $row['units'],
                'pms_ref' => $row['pms_ref'],
                'pricing_ref' => $row['pricing_ref'],
                'base' => $row['base'],
                'min' => $row['min'],
                'max' => $row['max'],
                'reference_min' => self::existingReference($row['code']) ?? $row['min'],
                'tags' => $row['tags'],
            ];
        }
        $saved = ScreenServices::instance()->wizard()->saveRooms($rows);
        /* translators: %s: number of rooms */
        Notice::flash(Notice::SUCCESS, sprintf(_n('%s room saved.', '%s rooms saved.', $saved, 'ritmo-revenue'), number_format_i18n($saved)));

        return ['tab' => 'setup', 'step' => 6, 'property' => $property];
    }

    /**
     * @return array<string, string|int>
     */
    private static function saveRules(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $services = ScreenServices::instance();
        if ($form->choice('rules', ['conservative', 'import'], 'conservative') === 'import') {
            $summary = $services->services()->setup()->importPreset(self::presetFile(), get_current_user_id());
            /* translators: 1: rules version, 2: properties, 3: room types */
            Notice::flash(Notice::SUCCESS, sprintf(__('Preset imported: rules version %1$d, %2$d properties and %3$d room types, all in shadow mode.', 'ritmo-revenue'), $summary['rules_version'], $summary['properties'], $summary['room_types']));
        }
        $days = array_map('intval', $form->list('weekend', '/^[0-6]$/', 7));
        if ($days === []) {
            throw new UserFacing(__('Choose at least one weekend night.', 'ritmo-revenue'));
        }
        $version = $services->wizard()->chooseRules($days, get_current_user_id());
        /* translators: %d: rules version */
        Notice::flash(Notice::SUCCESS, sprintf(__('Rules saved as version %d. They apply from the next run; no price was written.', 'ritmo-revenue'), $version));

        return ['tab' => 'setup', 'step' => 7, 'property' => $property];
    }

    /**
     * @return array<string, string|int>
     */
    private static function confirmSafety(Input $form): array
    {
        $property = $form->text('property_id', 32);
        ConfirmTyped::check($property, $form->text('confirmation', 32));
        $references = [];
        foreach ($form->rows('reference', ['code', 'amount'], 200) as $row) {
            if ($row['code'] !== '' && preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $row['amount']) === 1) {
                $references[$row['code']] = (float) str_replace(',', '.', $row['amount']);
            }
        }
        ScreenServices::instance()->wizard()->confirmSafety($property, [
            'floors.fraction_of_reference_min' => Ratio::fromPercentText($form->text('fraction', 10)),
            'safety.weekly_listing_change_max' => Ratio::fromPercentText($form->text('weekly', 10)),
            'write.listing_changes_enabled' => $form->bool('listing_changes'),
            'modes.autonomous_enabled' => $form->bool('autonomous'),
        ], $references, get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Safety limits confirmed. They apply from the next run.', 'ritmo-revenue'));

        return ['tab' => 'setup', 'step' => 8, 'property' => $property];
    }

    private static function existingReference(string $code): ?string
    {
        $room = $code === '' ? null : ScreenServices::instance()->services()->roomTypes()->find($code);

        return $room === null ? null : (string) $room->referenceMin;
    }

    /**
     * The preset file of the rules step: a .json upload of at most 1 MB, read in place and never
     * kept.
     */
    private static function presetFile(): string
    {
        // The nonce and the capability were checked by ScreenActions::handle() before this runs.
        $file = $_FILES['preset_file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new UserFacing(__('Choose a preset file to import.', 'ritmo-revenue'));
        }
        if ((int) ($file['size'] ?? 0) > self::PRESET_MAX_BYTES || strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'json') {
            throw new UserFacing(__('A preset is a .json file of at most 1 MB.', 'ritmo-revenue'));
        }
        $json = (string) file_get_contents($file['tmp_name']);
        if (str_contains($json, "\0")) {
            throw new UserFacing(__('A preset is a .json file of at most 1 MB.', 'ritmo-revenue'));
        }

        return $json;
    }
}
