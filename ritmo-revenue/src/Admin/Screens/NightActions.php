<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Admin\Notice;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;

/**
 * The form of the night detail: releasing a freeze after a manual change (approvers only, a
 * nonce for that freeze, a ticked confirmation). The next run may change the night again.
 */
final class NightActions
{
    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [new FormAction('ritmo_freeze_release', Capability::APPROVE, Menu::NIGHTS, self::release(...), true, 'freeze_id')];
    }

    /**
     * @return array<string, string>
     */
    private static function release(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $back = ['property' => $property, 'room' => $form->text('room', 32), 'night' => $form->text('night', 10)];
        if (!$form->bool('understood')) {
            throw new UserFacing(__('Tick the box to confirm you understand the plugin may change this night again.', 'ritmo-revenue'));
        }
        $id = $form->int('freeze_id', 0, 0);
        $services = ScreenServices::instance()->services();
        $stack = $services->stack();
        $owned = array_filter($stack->freezes()->unreleased($property), static fn ($f): bool => $f->id === $id);
        if ($owned === [] || !$stack->freezes()->release($id, get_current_user_id(), $services->clock()->now())) {
            throw new UserFacing(__('This freeze was already released or does not exist.', 'ritmo-revenue'));
        }
        $stack->events()->record($property, OperationEventRepository::FREEZE_RELEASE, 'frozen', 'released', sprintf('Freeze %d released.', $id), 'user:' . get_current_user_id(), $services->clock()->now());
        Notice::flash(Notice::SUCCESS, __('Freeze released: the next run may change this night again.', 'ritmo-revenue'));

        return $back;
    }
}
