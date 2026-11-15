<?php

declare(strict_types=1);

namespace Ritmo\Admin\Licence;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Admin\Notice;
use Ritmo\Application\Licence\LicenceRefused;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Domain\Licence\LicenceVerdict;

/**
 * The forms of Settings › Licence, for those who manage the connections (POST, capability and
 * nonce are checked by Screens\ScreenActions before these handlers read anything):
 * save and activate a key, check now, remove the key (ticked confirmation), check for updates.
 */
final class LicenceActions
{
    private const MAX_TYPED = 512;

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
            new FormAction('ritmo_licence_save', $c, Menu::SETTINGS, self::save(...)),
            new FormAction('ritmo_licence_check', $c, Menu::SETTINGS, self::check(...)),
            new FormAction('ritmo_licence_remove', $c, Menu::SETTINGS, self::remove(...)),
            new FormAction('ritmo_update_check', $c, Menu::SETTINGS, self::updates(...)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function save(Input $form): array
    {
        $typed = $form->secret('licence_key');
        if ($typed === '' || strlen($typed) > self::MAX_TYPED) {
            throw new UserFacing(__('Type the licence key.', 'ritmo-revenue'));
        }
        try {
            $verdict = LicenceServices::instance()->licence()->save($typed);
        } catch (LicenceRefused $refused) {
            throw new UserFacing(LicenceText::refusal($refused));
        }
        self::result($verdict, __('Licence key saved.', 'ritmo-revenue'));

        return ['tab' => LicenceScreen::TAB];
    }

    /**
     * @return array<string, string>
     */
    private static function check(Input $form): array
    {
        try {
            $verdict = LicenceServices::instance()->licence()->check();
        } catch (LicenceRefused $refused) {
            throw new UserFacing(LicenceText::refusal($refused));
        }
        self::result($verdict, __('Licence checked.', 'ritmo-revenue'));

        return ['tab' => LicenceScreen::TAB];
    }

    /**
     * @return array<string, string>
     */
    private static function remove(Input $form): array
    {
        if (!$form->bool('confirm')) {
            throw new UserFacing(__('Tick the box to confirm that the licence key is removed.', 'ritmo-revenue'));
        }
        $freed = LicenceServices::instance()->licence()->remove();
        Notice::flash(Notice::SUCCESS, $freed
            ? __('The licence key was removed and freed on the licence server.', 'ritmo-revenue')
            : __('The licence key was removed from this site. The licence server could not confirm it was freed.', 'ritmo-revenue'));

        return ['tab' => LicenceScreen::TAB];
    }

    /**
     * Asks the update server now, then lets WordPress rebuild its list of updates.
     *
     * @return array<string, string>
     */
    private static function updates(Input $form): array
    {
        $checker = LicenceServices::instance()->updates();
        $release = $checker->latest(true);
        delete_site_transient('update_plugins');
        $problem = $checker->lastProblem();
        if ($release !== null) {
            /* translators: %s: version number */
            Notice::flash(Notice::SUCCESS, sprintf(__('Version %s is available, signed by the publisher.', 'ritmo-revenue'), $release->version));
        } elseif ($problem !== null) {
            Notice::flash(Notice::WARNING, LicenceText::updateRefusal($problem));
        } else {
            Notice::flash(Notice::SUCCESS, __('No newer signed version is known.', 'ritmo-revenue'));
        }

        return ['tab' => LicenceScreen::TAB];
    }

    private static function result(LicenceVerdict $verdict, string $done): void
    {
        $type = match ($verdict->standing) {
            LicenceStanding::Active => Notice::SUCCESS,
            LicenceStanding::Grace => Notice::WARNING,
            default => Notice::ERROR,
        };
        Notice::flash($type, $done . ' ' . LicenceText::reason($verdict));
    }
}
