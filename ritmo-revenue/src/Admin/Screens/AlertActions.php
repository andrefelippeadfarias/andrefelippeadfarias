<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Admin\Notice;
use Ritmo\Application\Approval\WaveHoldService;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Schedule\WpRecipientDirectory;
use Ritmo\WordPress\WpCapabilityCheck;

/**
 * The forms of the Alerts screen: acknowledge (anyone who sees it; security and write alerts
 * only approvers, checked again by AlertService), resolve by hand (approvers), release the hold
 * of price cuts after a cancellation wave (approvers, ticked confirmation), and the user's own
 * e-mail preference.
 */
final class AlertActions
{
    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [
            new FormAction('ritmo_alert_acknowledge', Capability::VIEW, Menu::ALERTS, self::acknowledge(...), true, 'alert_id'),
            new FormAction('ritmo_alert_resolve', Capability::APPROVE, Menu::ALERTS, self::resolve(...), true, 'alert_id'),
            new FormAction('ritmo_wave_release', Capability::APPROVE, Menu::ALERTS, self::releaseWave(...), true, 'property_id'),
            new FormAction('ritmo_alert_email_pref', Capability::VIEW, Menu::ALERTS, self::emailPreference(...)),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function acknowledge(Input $form): array
    {
        $id = $form->int('alert_id', 0, 0);
        ScreenServices::instance()->services()->stack()->alerts()->acknowledge($id, get_current_user_id(), Capability::can(Capability::APPROVE));
        Notice::flash(Notice::SUCCESS, __('Alert acknowledged. It stays in the list until it is resolved.', 'ritmo-revenue'));

        return ['property' => $form->text('property_id', 32), 'alert' => $id];
    }

    /**
     * @return array<string, string|int>
     */
    private static function resolve(Input $form): array
    {
        $id = $form->int('alert_id', 0, 0);
        ScreenServices::instance()->services()->stack()->alerts()->resolve($id, get_current_user_id(), Capability::can(Capability::APPROVE));
        Notice::flash(Notice::SUCCESS, __('Alert resolved.', 'ritmo-revenue'));

        return ['property' => $form->text('property_id', 32), 'alert' => $id];
    }

    /**
     * @return array<string, string>
     */
    private static function releaseWave(Input $form): array
    {
        $property = $form->text('property_id', 32);
        if (!$form->bool('checked')) {
            throw new UserFacing(__('Tick the box to confirm you checked these cancellations.', 'ritmo-revenue'));
        }
        $services = ScreenServices::instance()->services();
        $stack = $services->stack();
        $released = (new WaveHoldService($stack->holds(), new WpCapabilityCheck(), $services->clock(), $stack->rules()->waves()))->release($property, get_current_user_id());
        if ($released) {
            $stack->events()->record($property, OperationEventRepository::WAVE_RELEASE, 'held', 'released', 'Hold of price cuts released.', 'user:' . get_current_user_id(), $services->clock()->now());
        }
        Notice::flash(Notice::SUCCESS, $released ? __('The hold of price cuts was released.', 'ritmo-revenue') : __('There was no hold to release.', 'ritmo-revenue'));

        return ['property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private static function emailPreference(Input $form): array
    {
        update_user_meta(get_current_user_id(), WpRecipientDirectory::OPT_OUT_META, $form->bool('email') ? 'yes' : 'no');
        Notice::flash(Notice::SUCCESS, __('Your e-mail preference was saved.', 'ritmo-revenue'));

        return ['property' => $form->text('property_id', 32)];
    }
}
