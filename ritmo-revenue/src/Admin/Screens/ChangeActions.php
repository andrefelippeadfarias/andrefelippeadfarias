<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\CsvWriter;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Admin\Notice;
use Ritmo\Application\Dashboard\Changes\ChangeGroups;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;

/**
 * The forms of the Change log: prepare the undo of the ticked changes (approvers), and the CSV of
 * the filtered rows (anyone who sees them; formula-guarded, before and after amounts).
 */
final class ChangeActions
{
    public const EXPORT_ROWS = 5000;

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [
            new FormAction('ritmo_undo_request', Capability::APPROVE, Menu::CHANGES, self::undo(...)),
            new FormAction('ritmo_changes_export', Capability::VIEW, Menu::CHANGES, self::export(...), download: true),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function undo(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $ids = array_map('intval', $form->list('changes', '/^\d{1,18}$/', 500));
        if ($ids === []) {
            throw new UserFacing(__('Tick the changes to undo.', 'ritmo-revenue'));
        }
        $stack = ScreenServices::instance()->services()->stack();
        $result = (new UndoNow($stack->undoWriter(), $stack->rules()))->request($property, $ids, get_current_user_id());
        $details = array_values(array_unique(array_map(UndoText::reason(...), $result['left_out'])));
        if ($result['request_id'] !== null) {
            /* translators: %s: number of changes */
            Notice::flash(Notice::SUCCESS, sprintf(_n('The undo of %s change is waiting for approval.', 'The undo of %s changes is waiting for approval.', count($ids), 'ritmo-revenue'), number_format_i18n(count($ids))), $details);

            return ['page' => Menu::APPROVALS, 'property' => $property, 'request' => $result['request_id']];
        }
        $status = UndoText::status($result['status']);
        Notice::flash(
            $result['undone'] > 0 ? Notice::SUCCESS : Notice::WARNING,
            $status !== '' ? $status : sprintf(
                /* translators: %s: number of changes undone */
                _n('%s change undone.', '%s changes undone.', $result['undone'], 'ritmo-revenue'),
                number_format_i18n($result['undone'])
            ),
            $details
        );

        return ['property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private static function export(Input $form): array
    {
        $filter = ChangesScreen::filter($form, $form->text('property_id', 32));
        $rows = ScreenServices::instance()->changes()->page($filter, 0, self::EXPORT_ROWS);
        CsvWriter::send(
            sprintf('ritmo-changes-%s-%s.csv', $filter->propertyId, gmdate('Ymd')),
            ['run', 'changed_at', 'property', 'room', 'night', 'system', 'change', 'before', 'after', 'before_amount', 'after_amount', 'reason', 'order', 'who', 'undone_at'],
            array_map(self::row(...), $rows)
        );

        return [];
    }

    /**
     * @return list<string|int|float|null>
     */
    private static function row(ChangeRecord $c): array
    {
        $user = ChangeGroups::userId($c->actor);

        return [
            $c->runId,
            $c->createdAt->format(DATE_ATOM),
            $c->propertyId,
            $c->roomCode,
            $c->night === null ? '' : Days::key($c->night),
            $c->provider->value,
            Labels::field($c->field),
            $c->oldValue,
            $c->newValue,
            $c->oldAmount,
            $c->newAmount,
            NightText::reason($c->reasonCode),
            $c->orderId ?? '',
            $c->undoOf !== null ? 'undo' : ($user === null ? 'autonomous' : 'user ' . $user),
            $c->undoneAt?->format(DATE_ATOM) ?? '',
        ];
    }
}
