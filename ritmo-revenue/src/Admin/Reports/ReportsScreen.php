<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Screen;
use Ritmo\Admin\Components\Tab;
use Ritmo\Admin\Editors\EditorPage;
use Ritmo\Application\Report\Store\TargetRow;
use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Support\Days;

/**
 * The Reports screen (spec 05, 5.15; spec 06, 2): the weekly panel, the month, the channels and
 * rooms of the last 28 days, the next 12 months, the targets and forecast, occupancy and the run
 * reports. Every tab: a summary sentence first, then the table(s) with a caption, then the CSV.
 * Reading reports takes ritmo_view; typing targets and saving a review take ritmo_manage_rules.
 */
final class ReportsScreen
{
    private function __construct()
    {
    }

    /**
     * @return list<Screen>
     */
    public static function screens(): array
    {
        return [new Screen(Menu::REPORTS, __('Reports', 'ritmo-revenue'), Capability::VIEW, self::tabs(), self::render(...))];
    }

    /**
     * @return list<Tab>
     */
    public static function tabs(): array
    {
        $v = Capability::VIEW;

        return [
            new Tab('weekly', __('Weekly performance', 'ritmo-revenue'), $v),
            new Tab('month', __('Month and goals', 'ritmo-revenue'), $v),
            new Tab('channels', __('Channels', 'ritmo-revenue'), $v),
            new Tab('rooms', __('Rooms', 'ritmo-revenue'), $v),
            new Tab('review', __('Next 12 months', 'ritmo-revenue'), $v),
            new Tab('targets', __('Targets', 'ritmo-revenue'), $v),
            new Tab('occupancy', __('Occupancy', 'ritmo-revenue'), $v),
            new Tab('runs', __('Run reports', 'ritmo-revenue'), $v),
        ];
    }

    public static function render(): void
    {
        $page = EditorPage::open(Menu::REPORTS, __('Reports', 'ritmo-revenue'), Capability::VIEW, self::tabs());
        if ($page->property === null) {
            $page->render('admin/reports/screen', ['no_property' => true]);

            return;
        }
        $services = ReportsServices::instance();
        $now = $services->services()->clock()->now();
        $today = Days::localDate($now, $page->property->timezone);
        $scope = $services->scope($page->property);
        $month = ReportViews::monthOf(Input::query()->text('month', 7), $today);
        $view = ['no_property' => false, 'tab' => $page->tab, 'month' => $month, 'months' => Period::following(Days::add($today, -365), 25), 'may_edit' => Capability::can(Capability::MANAGE_RULES), 'currency' => $page->currency()];
        if ($page->tab === 'runs') {
            $view['runs'] = self::runs($page->property->id);
        } else {
            $view += ReportViews::of($page->tab, $services->reports(), $scope, $now, $month, $page->roomNames());
            $view['empty'] = self::isEmpty($view['tables']);
        }
        if ($page->tab === 'review') {
            $view['due'] = $services->reports()->reviewDue($scope, $today);
        }
        if ($page->tab === 'targets') {
            $view['target_rows'] = $services->targetStore()->forProperty($page->property->id, Period::following($today, 12));
            $view['target_months'] = Period::following($today, 12);
            $view['target_fields'] = TargetRow::FIELDS;
        }
        $page->render('admin/reports/screen', $view);
    }

    /**
     * @return list<array{started: string, kind: string, status: string, lines: list<string>, recorded: bool}>
     */
    private static function runs(string $propertyId): array
    {
        $out = [];
        foreach (ReportsServices::instance()->services()->runs()->latest($propertyId, 20) as $run) {
            $text = RunLineText::ofSummary($run['summary']);
            $out[] = ['started' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $run['started_at']->getTimestamp()), 'kind' => $run['kind'], 'status' => $run['status'], ...$text];
        }

        return $out;
    }

    /**
     * @param list<ReportTable> $tables
     */
    private static function isEmpty(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$table->isEmpty()) {
                return false;
            }
        }

        return true;
    }
}
