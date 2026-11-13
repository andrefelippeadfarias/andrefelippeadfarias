<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Paging;
use Ritmo\Admin\Components\Percent;
use Ritmo\Admin\Components\PriceText;
use Ritmo\Application\Dashboard\Changes\ChangeFilter;
use Ritmo\Application\Dashboard\Changes\ChangeGroups;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Throwable;

/**
 * Change log (admin spec 5.11): every change the plugin made, grouped by run, before and after in
 * money, why and who allowed it; approvers tick changes to prepare their undo.
 */
final class ChangesScreen
{
    public const PER_PAGE = 50;

    private function __construct()
    {
    }

    public static function render(): void
    {
        $page = Page::open(Menu::CHANGES, __('Change log', 'ritmo-revenue'), Capability::VIEW);
        $property = $page->property;
        if ($property === null) {
            $page->render('changes', ['empty' => true, 'groups' => [], 'error' => null]);

            return;
        }
        $query = Input::query();
        $filter = self::filter($query, $property->id);
        try {
            $list = ScreenServices::instance()->changes();
            $paging = new Paging($query->int('paged', 1, 1, 100000), self::PER_PAGE, $list->count($filter));
            $groups = ChangeGroups::byRun($list->page($filter, $paging->offset(), self::PER_PAGE));
        } catch (Throwable $failure) {
            $page->render('changes', ['empty' => false, 'groups' => [], 'error' => ErrorText::from($failure)]);

            return;
        }
        $rooms = ScreenServices::instance()->services()->roomTypes()->forProperty($property->id);
        $names = [];
        foreach ($rooms as $room) {
            $names[$room->code] = $room->name;
        }
        $page->render('changes', [
            'empty' => $paging->total === 0,
            'error' => null,
            'groups' => array_map(static fn (array $g): array => self::group($g, $property, $names), $groups),
            'filter' => $filter->query() + ['room_options' => array_map(static fn ($r): array => ['code' => $r->code, 'name' => $r->name], $rooms)],
            'paging' => [
                'label' => $paging->label(),
                'previous' => $paging->hasPrevious() ? $page->url(['paged' => $paging->page - 1] + $filter->query()) : '',
                'next' => $paging->hasNext() ? $page->url(['paged' => $paging->page + 1] + $filter->query()) : '',
            ],
            'can_undo' => Capability::can(Capability::APPROVE),
            'property_id' => $property->id,
            'clear' => $page->url(),
        ]);
    }

    public static function filter(Input $input, string $propertyId): ChangeFilter
    {
        return new ChangeFilter(
            $propertyId,
            preg_match('/^[A-Za-z0-9_-]{1,32}$/', $input->text('room', 32)) === 1 ? $input->text('room', 32) : '',
            $input->date('night_from'),
            $input->date('night_to'),
            $input->date('changed_from'),
            $input->date('changed_to'),
            $input->choice('system', ChangeFilter::SYSTEMS, ''),
            $input->choice('who', ChangeFilter::WHO, ''),
            $input->bool('not_undone'),
        );
    }

    /**
     * @param array{run_id: int|null, actor: string, user_id: int|null, undo: bool, at: \DateTimeImmutable, rows: list<ChangeRecord>} $group
     * @param array<string, string>                                                                                                 $names
     *
     * @return array<string, mixed>
     */
    private static function group(array $group, Property $property, array $names): array
    {
        $when = NightDate::instant($group['at'], $property->timezone);
        $title = match (true) {
            /* translators: 1: name, 2: date and time */
            $group['undo'] => sprintf(__('Undo by %1$s · %2$s', 'ritmo-revenue'), $group['user_id'] === null ? __('the plugin', 'ritmo-revenue') : PeopleNames::name($group['user_id']), $when),
            /* translators: 1: name, 2: date and time */
            $group['user_id'] !== null => sprintf(__('Approved write · approved by %1$s · %2$s', 'ritmo-revenue'), PeopleNames::name($group['user_id']), $when),
            /* translators: %s: date and time */
            default => sprintf(__('Autonomous run · %s', 'ritmo-revenue'), $when),
        };

        return [
            'title' => $title,
            'rows' => array_map(static fn (ChangeRecord $c): array => self::row($c, $property, $names), $group['rows']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, string> $names room code => name
     */
    private static function row(ChangeRecord $change, Property $property, array $names): array
    {
        return [
            'id' => (int) $change->id,
            'room' => $names[$change->roomCode] ?? $change->roomCode,
            'night' => $change->night === null ? '—' : NightDate::short($change->night),
            'system' => Labels::provider($change->provider->value),
            'what' => ucfirst(Labels::field($change->field)),
            'price' => $change->oldAmount !== null || $change->newAmount !== null
                ? PriceText::change($change->oldAmount, $change->newAmount, $property->currency)
                : ['text' => self::plain($change->field, $change->oldValue) . ' → ' . self::plain($change->field, $change->newValue), 'note' => '', 'sr' => ''],
            'reason' => $change->reasonCode === '' ? '' : NightText::reason($change->reasonCode),
            'confirm' => match ($change->confirmLevel) {
                'live' => __('seen on the channel', 'ritmo-revenue'),
                'stored' => __('stored', 'ritmo-revenue'),
                default => '',
            },
            'undone' => $change->undoneAt === null ? '' : sprintf(
                /* translators: %s: date and time */
                __('undone %s', 'ritmo-revenue'),
                NightDate::instant($change->undoneAt, $property->timezone)
            ),
            'undoable' => $change->undoneAt === null && $change->undoOf === null && $change->night !== null && $change->night >= Days::date(gmdate('Y-m-d')),
        ];
    }

    private static function plain(string $field, int|float|string|null $value): string
    {
        return match (true) {
            $value === null => '—',
            str_ends_with($field, '_pct') && is_numeric($value) => Percent::ofBase((int) $value),
            $field === 'rule' => $value === 'present' ? __('set', 'ritmo-revenue') : __('none', 'ritmo-revenue'),
            is_string($value) && preg_match('/^[01]{7}$/', $value) === 1 => __('days of the week', 'ritmo-revenue'),
            default => (string) $value,
        };
    }
}
