<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use InvalidArgumentException;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\CsvWriter;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Notice;
use Ritmo\Application\Report\Store\TargetRow;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;

/**
 * The form actions of the Reports screen: the CSV of a table (ritmo_view), saving the 12-month
 * review (ritmo_manage_rules) and typing a month's targets (ritmo_manage_rules). Capability and
 * nonce are checked by Screens\ScreenActions before the handler; nothing here writes to an external system.
 */
final class ReportsActions
{
    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        $page = Menu::REPORTS;

        return [
            new FormAction('ritmo_export_report', Capability::VIEW, $page, self::export(...), false, '', true),
            new FormAction('ritmo_review_snapshot', Capability::MANAGE_RULES, $page, self::snapshot(...)),
            new FormAction('ritmo_targets_save', Capability::MANAGE_RULES, $page, self::saveTargets(...)),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function export(Input $form): array
    {
        $property = self::property($form);
        if ($property === null) {
            return ['failed' => 1];
        }
        $tab = $form->choice('tab', ReportViews::TABS, 'weekly');
        $services = ReportsServices::instance();
        $now = $services->services()->clock()->now();
        $month = ReportViews::monthOf($form->text('month', 7), Days::localDate($now, $property->timezone));
        $names = [];
        foreach ($services->services()->roomTypes()->forProperty($property->id) as $room) {
            $names[$room->code] = $room->name;
        }
        $tables = ReportViews::of($tab, $services->reports(), $services->scope($property), $now, $month, $names)['tables'];
        $wanted = $form->text('table', 32);
        foreach ($tables as $table) {
            if ($table->id === $wanted || $wanted === '') {
                CsvWriter::send(sprintf('ritmo-%s-%s-%s.csv', $table->id, $property->id, Days::key(Days::localDate($now, $property->timezone))), $table->csvHeader(), $table->csvRows($property->currency));

                return [];
            }
        }
        Notice::flash(Notice::ERROR, __('That table does not exist.', 'ritmo-revenue'));

        return ['tab' => $tab, 'failed' => 1];
    }

    /**
     * @return array<string, string|int>
     */
    private static function snapshot(Input $form): array
    {
        $property = self::property($form);
        if ($property === null) {
            return ['tab' => 'review'];
        }
        $services = ReportsServices::instance();
        $services->reports()->saveReview($services->scope($property), $services->services()->clock()->now(), get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Review saved. The next review compares each month with this one.', 'ritmo-revenue'));

        return ['tab' => 'review', 'property' => $property->id];
    }

    /**
     * @return array<string, string|int>
     */
    private static function saveTargets(Input $form): array
    {
        $property = self::property($form);
        if ($property === null) {
            return ['tab' => 'targets'];
        }
        $month = $form->text('month', 7);
        $values = [];
        foreach (TargetRow::FIELDS as $field) {
            $raw = trim($form->text('target_' . $field, 20));
            $values[$field] = $raw === '' ? null : self::number($raw);
        }
        try {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || in_array(false, $values, true)) {
                throw new InvalidArgumentException('Invalid month or number.');
            }
            /** @var array<string, float|null> $values */
            $services = ReportsServices::instance();
            $services->targetStore()->save($property->id, new TargetRow($month, $values), get_current_user_id(), $services->services()->clock()->now());
            Notice::flash(Notice::SUCCESS, __('Targets saved. They change what the reports compare with, never a price.', 'ritmo-revenue'));
        } catch (InvalidArgumentException) {
            Notice::flash(Notice::ERROR, __('The targets were not saved: use numbers (a dot or a comma for decimals), percentages from 0 to 100 and an occupancy cap above 0 and at most 1.', 'ritmo-revenue'));
        }

        return ['tab' => 'targets', 'property' => $property->id];
    }

    private static function property(Input $form): ?Property
    {
        $property = ReportsServices::instance()->services()->properties()->find($form->text('property', 64));
        if ($property === null) {
            Notice::flash(Notice::ERROR, __('Choose an existing property.', 'ritmo-revenue'));
        }

        return $property;
    }

    /**
     * A typed amount or percentage ("1.234,56", "1234.56", "45"); false when it is not a number.
     */
    public static function number(string $raw): float|false
    {
        $clean = str_replace([' ', "\u{00A0}"], '', $raw);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $clean) === 1) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        } elseif (preg_match('/^\d+,\d+$/', $clean) === 1) {
            $clean = str_replace(',', '.', $clean);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $clean) === 1) {
            $clean = str_replace(',', '', $clean);
        }

        return preg_match('/^\d+(\.\d+)?$/', $clean) === 1 ? (float) $clean : false;
    }
}
