<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\PrivacyGuard;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Admin\Notice;
use Ritmo\Application\Dashboard\Todo\TodoService;

/**
 * The forms of the To-do screen: add an item (texts pass the privacy guard), close and reopen
 * one (a nonce for that item).
 */
final class TodoActions
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
            new FormAction('ritmo_todo_add', Capability::MANAGE_RULES, Menu::TO_DO, self::add(...)),
            new FormAction('ritmo_todo_close', Capability::MANAGE_RULES, Menu::TO_DO, self::close(...), true, 'item_id'),
            new FormAction('ritmo_todo_reopen', Capability::MANAGE_RULES, Menu::TO_DO, self::reopen(...), true, 'item_id'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function add(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $title = $form->text('title', TodoService::MAX_TITLE);
        $details = $form->multiline('details', TodoService::MAX_DETAILS);
        $refusal = PrivacyGuard::check($title . ' ' . $details);
        if ($refusal !== null) {
            throw new UserFacing($refusal);
        }
        if ($title === '') {
            throw new UserFacing(__('Write what needs to be done.', 'ritmo-revenue'));
        }
        ScreenServices::instance()->todos()->add($property, $title, $details, $form->date('due_on'), get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Added to the to-do list.', 'ritmo-revenue'));

        return ['property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private static function close(Input $form): array
    {
        $property = $form->text('property_id', 32);
        ScreenServices::instance()->todos()->close($property, $form->int('item_id', 0, 0), get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Marked as done.', 'ritmo-revenue'));

        return ['property' => $property, 'page' => $form->choice('back_to', [Menu::TO_DO, Menu::OVERVIEW], Menu::TO_DO)];
    }

    /**
     * @return array<string, string>
     */
    private static function reopen(Input $form): array
    {
        $property = $form->text('property_id', 32);
        ScreenServices::instance()->todos()->reopen($property, $form->int('item_id', 0, 0), get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Opened again.', 'ritmo-revenue'));

        return ['property' => $property];
    }
}
