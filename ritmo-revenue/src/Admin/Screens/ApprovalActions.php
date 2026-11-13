<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ConfirmTyped;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\PrivacyGuard;
use Ritmo\Admin\Components\UserFacing;
use Ritmo\Admin\Notice;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Grid\Approvals\RequestView;
use Ritmo\Domain\Support\Num;

/**
 * Deciding a request (admin spec 5.4): approve the ticked nights, approve everything, or refuse;
 * a nonce for that request. Listing values need, on top, the safety capability and the new amount
 * typed for each value ticked; a mismatch refuses the whole decision. The approved write starts in
 * the background from fresh reads, under the write gate; nothing is written here.
 */
final class ApprovalActions
{
    public const ENTRY_PATTERN = '/^(item|deletion|listing)\|[A-Za-z0-9_-]{1,32}\|[A-Za-z0-9_-]{1,40}$/';

    public const NOTE_MAX = 500;

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [new FormAction('ritmo_approval_decide', Capability::APPROVE, Menu::APPROVALS, self::decide(...), true, 'request_id')];
    }

    /**
     * @return array<string, string|int>
     */
    private static function decide(Input $form): array
    {
        $property = $form->text('property_id', 32);
        $request = $form->int('request_id', 0, 0);
        $decision = $form->choice('decision', ['selected', 'all', 'reject'], '');
        $note = $form->text('note', self::NOTE_MAX);
        $refusal = PrivacyGuard::check($note);
        if ($refusal !== null) {
            throw new UserFacing($refusal);
        }
        $stack = ScreenServices::instance()->services()->stack();
        $desk = $stack->approvalDesk();
        $config = $stack->rules()->approval();
        $back = ['property' => $property, 'request' => $request];
        if ($decision === 'reject') {
            $desk->reject($property, $request, get_current_user_id(), $config, $note);
            Notice::flash(Notice::SUCCESS, __('Plan refused: nothing will be written.', 'ritmo-revenue'));

            return $back;
        }
        if ($decision === '') {
            throw new UserFacing(__('Choose to approve the nights ticked, approve everything, or refuse the plan.', 'ritmo-revenue'));
        }
        $view = ScreenServices::instance()->approvals()->find($property, $request);
        $keys = self::keys($form, $view, $decision === 'all');
        $grant = $desk->approve($property, $request, get_current_user_id(), $keys, $config, $note);
        $added = array_values(array_diff(array_map(static fn (PlanEntry $e): string => $e->key(), $grant->approvedEntries), $keys ?? []));
        $count = count($grant->approvedEntries);
        Notice::flash(
            Notice::SUCCESS,
            sprintf(
                /* translators: %s: number of nights */
                _n('%s night approved. The plugin reads the calendars again and writes it now, in the background. You can follow it below.', '%s nights approved. The plugin reads the calendars again and writes them now, in the background. You can follow it below.', $count, 'ritmo-revenue'),
                number_format_i18n($count)
            ),
            $keys === null || $added === [] ? [] : [sprintf(
                /* translators: %s: number of entries */
                _n('%s linked entry was approved with your selection.', '%s linked entries were approved with your selection.', count($added), 'ritmo-revenue'),
                number_format_i18n(count($added))
            )]
        );

        return $back;
    }

    /**
     * The keys to approve: the nights ticked, or every night ("all"); listing values only when
     * ticked, confirmed by their typed amount and approved by someone allowed to change safety
     * limits.
     *
     * @return list<string>|null null approves every entry of a request without listing values
     */
    private static function keys(Input $form, RequestView $view, bool $all): ?array
    {
        $listing = $form->list('listing', self::ENTRY_PATTERN, 10);
        if ($listing !== [] && !Capability::can(Capability::MANAGE_SAFETY)) {
            throw new UserFacing(__('Only a person allowed to change safety limits can approve a change of the listing.', 'ritmo-revenue'));
        }
        foreach ($view->listing as $row) {
            if (in_array($row->key, $listing, true)) {
                ConfirmTyped::check(self::plain($row->after()), $form->text('confirm_' . md5($row->key), 32));
            }
        }
        if ($all) {
            $nights = [];
            foreach ($view->rooms as $segments) {
                foreach ($segments as $segment) {
                    array_push($nights, ...$segment->keys());
                }
            }

            return $view->listing === [] && $listing === [] ? null : [...$nights, ...$listing];
        }
        $keys = [...$form->list('entries', self::ENTRY_PATTERN), ...$listing];
        if ($keys === []) {
            throw new UserFacing(__('Tick the nights to approve, or approve everything.', 'ritmo-revenue'));
        }

        return $keys;
    }

    /** The amount as a person types it: digits, a dot only when there are cents. */
    public static function plain(?float $amount): string
    {
        if ($amount === null) {
            return '';
        }
        $rounded = Num::roundHalfEven($amount, 2);

        return abs($rounded - Num::roundHalfEven($rounded)) < 0.0000001 ? (string) (int) Num::roundHalfEven($rounded) : number_format($rounded, 2, '.', '');
    }
}
