<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Notice;
use Ritmo\Application\Rules\RulesRefused;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;

/**
 * The form actions of the Orders screen: create an order (or a copy that ends the original), end
 * an order, release a frozen night. Orders apply from the next run; nothing is written to an
 * external system here.
 */
final class OrdersActions
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
            new FormAction('ritmo_order_create', Capability::MANAGE_RULES, Menu::ORDERS, self::create(...)),
            new FormAction('ritmo_order_end', Capability::MANAGE_RULES, Menu::ORDERS, self::end(...), true, 'order'),
            new FormAction('ritmo_freeze_release', Capability::APPROVE, Menu::ORDERS, self::release(...), true, 'freeze'),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function create(Input $form): array
    {
        $fields = RequestFields::posted('order');
        $fields['rooms'] = array_values(array_filter((array) ($fields['rooms'] ?? []), 'is_string'));
        $fields['releases'] = array_values(array_filter((array) ($fields['releases'] ?? []), 'is_string'));
        $fields['nights'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($fields['nights'] ?? '')) ?: []), 'strlen'));
        $copy = $form->int('copy', 0, 0);
        $propertyId = $form->text('property', 64);
        try {
            EditorServices::instance()->orders()->create($propertyId, $fields, get_current_user_id(), $form->text('confirmation', 64), Capability::can(Capability::MANAGE_SAFETY), $copy > 0 && $form->bool('end_original') ? $copy : null);
        } catch (InvalidConfig $invalid) {
            $errors = [];
            foreach ($invalid->errors() as $error) {
                $errors['order.' . $error->path] = $error->path === 'note' ? EditorText::privacy($error->message) : self::problem($error->path, $error->code);
            }
            FormErrors::flash($errors);
            Notice::flash(Notice::ERROR, __('The order was not saved: correct the values marked below.', 'ritmo-revenue'), array_values($errors));

            return ['tab' => 'new', 'type' => (string) ($fields['type'] ?? '')] + ($copy > 0 ? ['copy' => $copy] : []);
        } catch (RulesRefused $refused) {
            Notice::flash(Notice::ERROR, EditorText::refusal($refused));

            return ['tab' => 'new', 'type' => (string) ($fields['type'] ?? '')] + ($copy > 0 ? ['copy' => $copy] : []);
        }
        Notice::flash(Notice::SUCCESS, __('Order saved. It applies from the next run.', 'ritmo-revenue'));

        return ['tab' => 'active'];
    }

    /**
     * @return array<string, string>
     */
    private static function end(Input $form): array
    {
        try {
            EditorServices::instance()->orders()->end($form->text('property', 64), $form->int('order', 0, 1), get_current_user_id());
            Notice::flash(Notice::SUCCESS, __('Order ended. The next run no longer follows it.', 'ritmo-revenue'));
        } catch (RulesRefused $refused) {
            Notice::flash(Notice::ERROR, EditorText::refusal($refused));
        }

        return ['tab' => 'ended'];
    }

    /**
     * @return array<string, string>
     */
    private static function release(Input $form): array
    {
        if (!$form->bool('understood')) {
            Notice::flash(Notice::ERROR, __('Tick the box to confirm you understand the plugin may change these nights again.', 'ritmo-revenue'));

            return ['tab' => 'freezes'];
        }
        try {
            EditorServices::instance()->orders()->releaseFreeze($form->text('property', 64), $form->int('freeze', 0, 1), get_current_user_id());
            Notice::flash(Notice::SUCCESS, __('Released: the plugin may change these nights again from the next run.', 'ritmo-revenue'));
        } catch (RulesRefused $refused) {
            Notice::flash(Notice::ERROR, EditorText::refusal($refused));
        }

        return ['tab' => 'freezes'];
    }

    /**
     * A field problem of the order form as a sentence, by the field and the kind of problem.
     */
    private static function problem(string $path, string $code): string
    {
        $field = match (true) {
            $path === 'type' => __('Kind of order', 'ritmo-revenue'),
            $path === 'rooms' => __('Rooms', 'ritmo-revenue'),
            $path === 'from' => __('First night', 'ritmo-revenue'),
            $path === 'to' => __('Last night', 'ritmo-revenue'),
            $path === 'nights' => __('Only these nights', 'ritmo-revenue'),
            $path === 'percentile' => __('Market percentile', 'ritmo-revenue'),
            $path === 'releases' => __('What it allows', 'ritmo-revenue'),
            str_starts_with($path, 'floors.') => __('Minimum price', 'ritmo-revenue'),
            default => __('Order', 'ritmo-revenue'),
        };
        $text = match ($code) {
            ValidationError::ORDER => __('the last night is before the first night.', 'ritmo-revenue'),
            ValidationError::RANGE => $path === 'percentile' ? __('type a market percentile from 0 to 110.', 'ritmo-revenue') : __('an order covers at most a year.', 'ritmo-revenue'),
            ValidationError::FORMAT => $path === 'nights' ? __('each line is a date.', 'ritmo-revenue') : __('type an amount above zero.', 'ritmo-revenue'),
            ValidationError::CHOICE => $path === 'releases' ? __('this kind of order cannot allow that.', 'ritmo-revenue') : __('choose what the order does.', 'ritmo-revenue'),
            default => __('give a value.', 'ritmo-revenue'),
        };

        return $field . ': ' . $text;
    }
}
