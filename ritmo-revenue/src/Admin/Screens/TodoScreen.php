<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Application\Dashboard\Todo\TodoItem;
use Ritmo\Domain\Model\Property;
use Throwable;

/**
 * To-do (admin spec 5.12): what only a person can do. The plugin's items are refreshed from stored
 * facts when the screen opens (they open and close themselves); people add, close and reopen.
 */
final class TodoScreen
{
    private function __construct()
    {
    }

    public static function render(): void
    {
        $page = Page::open(Menu::TO_DO, __('To-do', 'ritmo-revenue'), Capability::VIEW);
        $property = $page->property;
        if ($property === null) {
            $page->render('todo', ['error' => null, 'open' => [], 'done' => [], 'can_edit' => false, 'property_id' => '']);

            return;
        }
        $services = ScreenServices::instance();
        try {
            $stack = $services->services()->stack();
            $services->todoGenerator()->refresh($property->id, $property->timezone, $stack->rules(), $stack->changeLog()->latest($property->id, 200));
            $list = $services->todos()->list($property->id);
        } catch (Throwable $failure) {
            $page->render('todo', ['error' => ErrorText::from($failure), 'open' => [], 'done' => [], 'can_edit' => false, 'property_id' => $property->id]);

            return;
        }
        $page->render('todo', [
            'error' => null,
            'open' => array_map(static fn (TodoItem $t): array => self::item($t, $property), $list['open']),
            'done' => array_map(static fn (TodoItem $t): array => self::item($t, $property), $list['done']),
            'can_edit' => Capability::can(Capability::MANAGE_RULES),
            'property_id' => $property->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(TodoItem $item, Property $property): array
    {
        return [
            'id' => (int) $item->id,
            'title' => TodoText::title($item),
            'why' => TodoText::why($item),
            'where' => TodoText::where($item, $property->id),
            /* translators: %s: date */
            'due' => $item->dueOn === null ? '' : sprintf(__('due %s', 'ritmo-revenue'), NightDate::short($item->dueOn)),
            'by' => $item->source === TodoItem::PLUGIN ? __('Ritmo Revenue', 'ritmo-revenue') : ($item->createdBy > 0 ? PeopleNames::name($item->createdBy) : __('someone', 'ritmo-revenue')),
            'at' => $item->createdAt === null ? '' : NightDate::instant($item->createdAt, $property->timezone),
        ];
    }
}
