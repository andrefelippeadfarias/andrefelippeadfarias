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
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\State\ChangeField;
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
        $byCode = [];
        foreach ($rooms as $room) {
            $names[$room->code] = $room->name;
            $byCode[$room->code] = $room;
        }
        $page->render('changes', [
            'empty' => $paging->total === 0,
            'error' => null,
            'groups' => array_map(static fn (array $g): array => self::group($g, $property, $names, $byCode), $groups),
            'filter' => $filter->query() + ['room_options' => array_map(static fn ($r): array => ['code' => $r->code, 'name' => $r->name], $rooms)],
            'paging' => [
                'label' => $paging->label(),
                'previous' => $paging->hasPrevious() ? $page->url(['paged' => $paging->page - 1] + $filter->query()) : '',
                'next' => $paging->hasNext() ? $page->url(['paged' => $paging->page + 1] + $filter->query()) : '',
            ],
            'can_undo' => Capability::can(Capability::APPROVE),
            'undo_preview' => $query->text('undo', 8) === 'confirm' && Capability::can(Capability::APPROVE) ? self::undoPreview($property, $names, $byCode) : null,
            'shadow' => $property->mode === PropertyMode::Shadow,
            'property_id' => $property->id,
            'clear' => $page->url(),
        ]);
    }

    /**
     * What an undo of the ticked changes writes back, one row per change: the value now and the
     * value it goes back to.
     *
     * @param array<string, string>   $names room code => name
     * @param array<string, RoomType> $rooms room code => room
     *
     * @return array{ids: list<int>, rows: list<array<string, mixed>>}
     */
    private static function undoPreview(Property $property, array $names, array $rooms): array
    {
        $ids = UndoSelection::ids();
        $records = $ids === [] ? [] : ScreenServices::instance()->services()->stack()->changeLog()->byIds($ids);
        $records = array_values(array_filter($records, static fn (ChangeRecord $c): bool => $c->propertyId === $property->id && $c->undoneAt === null));

        return [
            'ids' => array_map(static fn (ChangeRecord $c): int => (int) $c->id, $records),
            'rows' => array_map(static fn (ChangeRecord $c): array => [
                'room' => $names[$c->roomCode] ?? $c->roomCode,
                'night' => $c->night === null ? '—' : NightDate::short($c->night),
                'what' => ucfirst(Labels::field($c->field)) . ' (' . Labels::provider($c->provider->value) . ')',
                'price' => self::back($c, $property, $rooms[$c->roomCode] ?? null),
            ], $records),
        ];
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
    private static function group(array $group, Property $property, array $names, array $rooms): array
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
            'rows' => array_map(static fn (ChangeRecord $c): array => self::row($c, $property, $names, $rooms[$c->roomCode] ?? null), $group['rows']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, string> $names room code => name
     */
    private static function row(ChangeRecord $change, Property $property, array $names, ?RoomType $room): array
    {
        return [
            'id' => (int) $change->id,
            'room' => $names[$change->roomCode] ?? $change->roomCode,
            'night' => $change->night === null ? '—' : NightDate::short($change->night),
            'system' => Labels::provider($change->provider->value),
            'what' => ucfirst(Labels::field($change->field)),
            'price' => self::price($change, $property, $room),
            'reason' => $change->reasonCode === '' ? '' : NightText::reason($change->reasonCode),
            'confirm' => match ($change->confirmLevel) {
                'live' => __('seen on the channel', 'ritmo-revenue'),
                'stored' => __('read back from the system', 'ritmo-revenue'),
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

    /**
     * Before and after in money. A floor or ceiling written on a night without a date rule had the
     * listing's minimum or maximum before (as the approval showed it), said in a note.
     *
     * @return array{text: string, note: string, sr: string}
     */
    private static function price(ChangeRecord $change, Property $property, ?RoomType $room): array
    {
        if ($change->oldAmount === null && $change->newAmount === null) {
            return ['text' => self::plain($change->field, $change->oldValue) . ' → ' . self::plain($change->field, $change->newValue), 'note' => '', 'sr' => ''];
        }
        $listing = $room === null || $change->oldAmount !== null ? null : match ($change->field) {
            ChangeField::FLOOR_PCT => [$room->min, __('before: the listing minimum, as the night had no date rule', 'ritmo-revenue')],
            ChangeField::CEILING_PCT => [$room->max, __('before: the listing maximum, as the night had no date rule', 'ritmo-revenue')],
            default => null,
        };
        $price = PriceText::change($listing[0] ?? $change->oldAmount, $change->newAmount, $property->currency);

        if ($listing === null) {
            return $price;
        }
        $note = implode('; ', array_filter([$price['note'], $listing[1]], static fn (string $p): bool => $p !== ''));

        return ['text' => $price['text'], 'note' => $note, 'sr' => $price['sr'] . ' (' . $listing[1] . ')'];
    }

    /**
     * The value now and the value an undo puts back. A floor or ceiling that had no date rule
     * before goes back to the listing's minimum or maximum, said in a note.
     *
     * @return array{text: string, note: string, sr: string}
     */
    private static function back(ChangeRecord $change, Property $property, ?RoomType $room): array
    {
        if ($change->oldAmount === null && $change->newAmount === null) {
            return ['text' => self::plain($change->field, $change->newValue) . ' → ' . self::plain($change->field, $change->oldValue), 'note' => '', 'sr' => ''];
        }
        $listing = $room === null || $change->oldAmount !== null ? null : match ($change->field) {
            ChangeField::FLOOR_PCT => [$room->min, __('back to the listing minimum: the night has no date rule', 'ritmo-revenue')],
            ChangeField::CEILING_PCT => [$room->max, __('back to the listing maximum: the night has no date rule', 'ritmo-revenue')],
            default => null,
        };
        $price = PriceText::change($change->newAmount, $listing[0] ?? $change->oldAmount, $property->currency);

        return $listing === null ? $price : ['text' => $price['text'], 'note' => $listing[1], 'sr' => $price['sr'] . ' (' . $listing[1] . ')'];
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
