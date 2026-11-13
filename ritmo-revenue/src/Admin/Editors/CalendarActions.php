<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use InvalidArgumentException;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Notice;
use Ritmo\Application\Rules\Calendar\HolidayProposals;
use Ritmo\Domain\Config\Schema;

/**
 * Stages the holiday periods of the calendar into the person's rules draft (spec 05, 5.7): add a
 * period, move it up or down (the first period covering a night wins), delete it, and add the
 * ticked national holidays of a preview. Weekend nights, seasons and packages use the rules form.
 */
final class CalendarActions
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function calendar(Input $form): array
    {
        $userId = get_current_user_id();
        $drafts = EditorServices::instance()->drafts();
        $rules = $drafts->rules($userId);
        $holidays = array_values(array_filter((array) ($rules['calendar']['holidays'] ?? []), 'is_array'));
        $index = $form->int('index', -1, -1, 1000);
        $op = $form->choice('op', ['add', 'up', 'down', 'delete'], 'add');
        if ($op !== 'add' && !isset($holidays[$index])) {
            return ['tab' => RulesLayout::CALENDAR];
        }
        switch ($op) {
            case 'up':
            case 'down':
                $other = $op === 'up' ? $index - 1 : $index + 1;
                if (isset($holidays[$other])) {
                    [$holidays[$index], $holidays[$other]] = [$holidays[$other], $holidays[$index]];
                }
                break;
            case 'delete':
                array_splice($holidays, $index, 1);
                break;
            default:
                $period = self::period($form);
                if ($period === null) {
                    return ['tab' => RulesLayout::CALENDAR];
                }
                $holidays[] = $period;
        }
        $rules['calendar']['holidays'] = $holidays;
        $drafts->stage($userId, $rules, ['calendar.holidays']);
        Notice::flash(Notice::SUCCESS, __('Change kept in your draft (not saved yet).', 'ritmo-revenue'));

        return ['tab' => RulesLayout::CALENDAR];
    }

    /**
     * @return array<string, string>
     */
    public static function holidays(Input $form): array
    {
        $back = ['tab' => RulesLayout::CALENDAR];
        try {
            $list = EditorServices::instance()->holidays()->load($form->text('country', 2));
        } catch (InvalidArgumentException) {
            Notice::flash(Notice::ERROR, __('There is no holiday list for that country.', 'ritmo-revenue'));

            return $back;
        }
        $years = array_map('intval', $form->list('years', '/^20\d\d$/', 3));
        $keys = $form->list('keys', '/^\d{4}-\d{2}-\d{2}:[a-z0-9_+]{1,200}$/', 100);
        $userId = get_current_user_id();
        $drafts = EditorServices::instance()->drafts();
        $rules = $drafts->rules($userId);
        $holidays = array_values(array_filter((array) ($rules['calendar']['holidays'] ?? []), 'is_array'));
        $added = 0;
        foreach (HolidayProposals::forYears($list, $years, $form->bool('bridges')) as $proposal) {
            if (in_array($proposal->key(), $keys, true)) {
                $holidays[] = $proposal->toPeriod(get_user_locale());
                ++$added;
            }
        }
        if ($added === 0) {
            Notice::flash(Notice::WARNING, __('No holiday was ticked: nothing was added.', 'ritmo-revenue'));

            return $back;
        }
        $rules['calendar']['holidays'] = $holidays;
        $drafts->stage($userId, $rules, ['calendar.holidays']);
        /* translators: %d: number of holiday periods */
        Notice::flash(Notice::SUCCESS, sprintf(_n('%d holiday period added to your draft (not saved yet).', '%d holiday periods added to your draft (not saved yet).', $added, 'ritmo-revenue'), $added));

        return $back;
    }

    /**
     * The period typed in the "Add a period" form, or null (with a notice).
     *
     * @return array<string, mixed>|null
     */
    private static function period(Input $form): ?array
    {
        $schema = Schema::ruleSet();
        $read = RuleFormReader::read($schema, FormScope::tab(RulesLayout::CALENDAR), ['calendar' => ['holidays' => []]], ['calendar' => ['holidays' => [RuleFormReader::NEW_ITEM => RequestFields::posted('period') + [RuleFormReader::ADD => '1']]]]);
        $period = $read->rules['calendar']['holidays'][0] ?? null;
        if ($read->errors !== [] || !is_array($period) || ($period['name'] ?? '') === '' || ($period['start'] ?? null) === null || ($period['end'] ?? null) === null || $period['end'] < $period['start']) {
            Notice::flash(Notice::ERROR, __('The period was not added: give a name, a first night and a last night not before it.', 'ritmo-revenue'));

            return null;
        }

        return $period;
    }
}
