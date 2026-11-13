<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Notice;
use Ritmo\Application\Dashboard\Setup\RoomRowsRefused;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Throwable;

/**
 * Runs a form action of the central screens (admin spec 6.1), in this order: POST only (405),
 * the capability (403), the nonce (per object when the action is about one object), then the
 * handler; any refusal becomes a notice in words, and the browser goes back to a screen of the
 * plugin (post/redirect/get).
 */
final class ScreenActions
{
    private function __construct()
    {
    }

    /**
     * @param list<FormAction> $actions
     */
    public static function register(array $actions): void
    {
        foreach ($actions as $action) {
            add_action('admin_post_' . $action->action, static fn () => self::handle($action));
        }
    }

    public static function handle(FormAction $action): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            wp_die(esc_html__('This address only accepts a form.', 'ritmo-revenue'), '', ['response' => 405]);
        }
        if (!current_user_can($action->capability)) {
            wp_die(esc_html__('You are not allowed to do this.', 'ritmo-revenue'), '', ['response' => 403]);
        }
        $form = Input::form();
        check_admin_referer($action->nonceAction($action->perObjectNonce ? $form->text($action->objectField, 64) : ''));

        $args = self::back($form);
        try {
            $args = ($action->handler)($form) + $args;
        } catch (Throwable $failure) {
            Notice::flash(Notice::ERROR, ErrorText::from($failure, self::log(...)), self::details($failure));
            $args['failed'] = true;
        }
        if ($action->download && !isset($args['failed'])) {
            exit;
        }
        $page = is_string($args['page'] ?? null) ? $args['page'] : $action->page;
        unset($args['page'], $args['failed']);
        wp_safe_redirect(Menu::url($page, $args));
        exit;
    }

    /**
     * Where to go back to when the handler failed: the same property and tab.
     *
     * @return array<string, string>
     */
    private static function back(Input $form): array
    {
        $property = $form->text('property_id', 64) !== '' ? $form->text('property_id', 64) : $form->text('property', 64);

        return array_filter([
            'property' => preg_match('/^[A-Za-z0-9_-]{1,32}$/', $property) === 1 ? $property : '',
            'tab' => preg_match('/^[a-z_]{1,32}$/', $form->text('tab', 32)) === 1 ? $form->text('tab', 32) : '',
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * @return list<string>
     */
    private static function details(Throwable $failure): array
    {
        if ($failure instanceof RoomRowsRefused) {
            /* translators: 1: row number, 2: why the row was refused */
            return array_map(static fn (array $e): string => sprintf(__('Room %1$d: %2$s', 'ritmo-revenue'), $e['row'], $e['message']), $failure->errors);
        }
        if ($failure instanceof InvalidConfig) {
            return array_values(array_unique(array_map(static fn (ValidationError $e): string => $e->message, $failure->errors())));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function log(string $reference, array $context): void
    {
        ScreenServices::instance()->services()->logger()->log('error', 'A screen action failed: ' . $reference, $context);
    }
}
