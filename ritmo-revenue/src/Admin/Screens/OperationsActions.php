<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Notice;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\WordPress\Roles;

/**
 * The forms of the Operations screen (admin spec 5.16) and the shadow run of the Overview and the
 * setup: run a shadow run, run now, change the mode (the use case checks who may go up or down),
 * pause and resume the scheduled runs, and the emergency switch (engage: approve or manage
 * connections; release: manage connections only, decision D23).
 */
final class OperationsActions
{
    private const PAUSE_HOURS = [1, 2, 4, 8, 24, 72, 168];

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        $r = Capability::MANAGE_RULES;

        return [
            new FormAction('ritmo_run_shadow', $r, Menu::OVERVIEW, self::runShadow(...)),
            new FormAction('ritmo_run_now', $r, Menu::OPERATIONS, self::runNow(...)),
            new FormAction('ritmo_set_mode', Capability::VIEW, Menu::OPERATIONS, self::setMode(...)),
            new FormAction('ritmo_pause', $r, Menu::OPERATIONS, self::pause(...)),
            new FormAction('ritmo_resume', $r, Menu::OPERATIONS, self::resume(...)),
            new FormAction('ritmo_emergency_operations', Capability::VIEW, Menu::OPERATIONS, self::emergency(...)),
        ];
    }

    /**
     * Who may move the emergency switch which way (D23).
     */
    public static function maySwitch(bool $engage): bool
    {
        return Roles::maySwitchEmergency($engage);
    }

    /**
     * @return array<string, string>
     */
    private static function runShadow(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $outcome = ScreenServices::instance()->services()->shadowRuns()->run($property);
        $ok = in_array($outcome->status, ['completed', 'running'], true);
        Notice::flash(
            $ok ? Notice::SUCCESS : Notice::ERROR,
            /* translators: %s: status of the run in words */
            sprintf(__('Shadow run: %s. Nothing was written to the pricing system or the PMS.', 'ritmo-revenue'), Labels::runStatus($outcome->status))
        );
        $back = $form->choice('back', [Menu::OVERVIEW, Menu::SETTINGS], Menu::OVERVIEW);

        return array_filter(['page' => $back, 'property' => $property, 'tab' => $back === Menu::SETTINGS ? 'setup' : '', 'step' => $back === Menu::SETTINGS ? ($ok ? '9' : '8') : '']);
    }

    /**
     * @return array<string, string>
     */
    private static function runNow(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $result = ScreenServices::instance()->services()->stack()->runNow()->run($property);
        Notice::flash(
            in_array($result->status->value, ['completed', 'running'], true) ? Notice::SUCCESS : Notice::ERROR,
            /* translators: %s: status of the run in words */
            sprintf(__('Run now: %s.', 'ritmo-revenue'), Labels::runStatus($result->status->value))
        );

        return ['property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private static function setMode(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $mode = PropertyMode::tryFrom($form->choice('mode', array_map(static fn (PropertyMode $m): string => $m->value, PropertyMode::cases()), ''))
            ?? throw new InvalidArgumentException(__('Choose a mode.', 'ritmo-revenue'));
        $stack = ScreenServices::instance()->services()->stack();
        $changed = $stack->modes()->change($property, $mode, get_current_user_id(), $stack->rules()->modes(), $form->text('confirmation', 64), $form->text('reason', 190));
        /* translators: %s: Shadow, Approval or Autonomous */
        Notice::flash(Notice::SUCCESS, sprintf(__('The property is now in the %s mode.', 'ritmo-revenue'), Labels::mode($changed->mode->value)));

        return ['property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private static function pause(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $hours = $form->int('hours', 0, 0, 168);
        $services = ScreenServices::instance()->services();
        $until = null;
        if ($hours > 0) {
            if (!in_array($hours, self::PAUSE_HOURS, true)) {
                throw new InvalidArgumentException(__('Choose how long the pause lasts.', 'ritmo-revenue'));
            }
            $until = $services->clock()->now()->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('+%d hours', $hours));
        }
        $services->stack()->pauses()->pause($property, $until, $form->text('reason', 190), get_current_user_id());
        Notice::flash(Notice::SUCCESS, $until instanceof DateTimeImmutable
            ? __('Scheduled runs are paused. "Run now" still works.', 'ritmo-revenue')
            : __('Scheduled runs are paused until someone resumes them. "Run now" still works.', 'ritmo-revenue'));

        return ['property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private static function resume(Input $form): array
    {
        $property = $form->text('property_id', 32);
        ScreenServices::instance()->services()->stack()->pauses()->resume($property, get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Scheduled runs resumed.', 'ritmo-revenue'));

        return ['property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private static function emergency(Input $form): array
    {
        $position = $form->choice('switch', ['engage', 'release'], '');
        if ($position === '') {
            throw new InvalidArgumentException(__('Choose to engage or to release the emergency switch.', 'ritmo-revenue'));
        }
        $engage = $position === 'engage';
        if (!self::maySwitch($engage)) {
            wp_die(esc_html__('You are not allowed to do this.', 'ritmo-revenue'), '', ['response' => 403]);
        }
        $engaged = ScreenServices::instance()->services()->stack()->emergency()->set($engage, 'Screen', 'user:' . get_current_user_id());
        if ($engage) {
            Notice::flash(Notice::WARNING, __('Emergency switch engaged: no run starts and running ones stop before their next stage.', 'ritmo-revenue'));
        } else {
            Notice::flash(Notice::SUCCESS, $engaged
                ? __('The option was released, but the constant RITMO_KILL_SWITCH in wp-config.php keeps the switch engaged.', 'ritmo-revenue')
                : __('Emergency switch released.', 'ritmo-revenue'));
        }

        return array_filter(['property' => $form->text('property_id', 32)]);
    }
}
