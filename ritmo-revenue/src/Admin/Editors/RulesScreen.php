<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Tab;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Support\Days;

/**
 * The Rules screen (spec 05, 5.5–5.7): tabs general (one section at a time), safety, relations,
 * calendar, versions and review. Every change goes into the person's draft; only "Save" on the
 * review tab makes a new version of the rules, and nothing here writes a price.
 */
final class RulesScreen
{
    private function __construct()
    {
    }

    /**
     * @return list<Tab>
     */
    public static function tabs(): array
    {
        $v = Capability::VIEW;

        return [
            new Tab(RulesLayout::GENERAL, __('General', 'ritmo-revenue'), $v),
            new Tab(RulesLayout::SAFETY, __('Safety limits', 'ritmo-revenue'), $v),
            new Tab(RulesLayout::RELATIONS, __('Room relations', 'ritmo-revenue'), $v),
            new Tab(RulesLayout::CALENDAR, __('Calendar', 'ritmo-revenue'), $v),
            new Tab('versions', __('Versions', 'ritmo-revenue'), $v),
            new Tab('review', __('Review and save', 'ritmo-revenue'), Capability::MANAGE_RULES),
        ];
    }

    /**
     * The section list of the general tab: id => title.
     *
     * @return array<string, string>
     */
    public static function sections(): array
    {
        return [
            'goals' => __('Goals', 'ritmo-revenue'),
            'pace' => __('Pace', 'ritmo-revenue'),
            'floors' => __('Floors and price plans', 'ritmo-revenue'),
            'ladder' => __('Sales ladder', 'ritmo-revenue'),
            'market' => __('Market', 'ritmo-revenue'),
            'soldout' => __('Sold-out nights, cadence, small changes', 'ritmo-revenue'),
            'stay' => __('Stay and arrival/departure', 'ritmo-revenue'),
            'orders' => __('Owner order defaults', 'ritmo-revenue'),
            'plan' => __('Plan and writing', 'ritmo-revenue'),
            'runs' => __('Runs and alerts', 'ritmo-revenue'),
        ];
    }

    public static function render(): void
    {
        $page = EditorPage::open(Menu::RULES, __('Rules', 'ritmo-revenue'), Capability::VIEW, self::tabs());
        $query = Input::query();
        $userId = get_current_user_id();
        $drafts = EditorServices::instance()->drafts();
        $draft = $drafts->current($userId);
        $rules = $draft?->rules ?? $drafts->inForce();
        $mayEdit = Capability::can(Capability::MANAGE_RULES);
        $common = ['draft_changes' => $drafts->changes($userId), 'may_edit' => $mayEdit];

        match ($page->tab) {
            RulesLayout::SAFETY => $page->render('admin/editors/rules-section', $common + self::section($page, FormScope::tab(RulesLayout::SAFETY), $rules, !Capability::can(Capability::MANAGE_SAFETY) || !$mayEdit)),
            RulesLayout::RELATIONS => $page->render('admin/editors/relations', $common + RelationsView::of($page, $rules, $query->text('add', 32), $query->text('edit', 64), $query->text('band', 64), $mayEdit)),
            RulesLayout::CALENDAR => $page->render('admin/editors/calendar', $common + CalendarView::of($page, $rules, self::month($query), self::import($query), !$mayEdit)),
            'versions' => $page->render('admin/editors/versions', $common + VersionsView::of($page, $query->int('paged', 1, 1, 10000), $query->has('from') ? $query->int('from', 0, 1) : null, $query->has('to') ? $query->int('to', 0, 1) : null)),
            'review' => $page->render('admin/editors/review', $common + ($draft === null ? ['review' => null] : ['review' => ReviewView::of($draft, $page, Capability::can(Capability::MANAGE_SAFETY))])),
            default => $page->render('admin/editors/rules-section', $common + self::general($page, $query->choice('section', array_keys(RulesLayout::SECTIONS), 'goals'), $rules, !$mayEdit)),
        };
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private static function general(EditorPage $page, string $section, array $rules, bool $readOnly): array
    {
        return ['section' => $section, 'sections' => self::sections()] + self::section($page, FormScope::section($section), $rules, $readOnly);
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private static function section(EditorPage $page, FormScope $scope, array $rules, bool $readOnly): array
    {
        $ctx = new FieldContext(Schema::ruleSet(), $scope, EditorServices::instance()->drafts()->inForce(), $page->roomNames(), $page->currency(), FormErrors::take(), $readOnly);

        return ['scope' => $scope->id(), 'scope_tab' => $scope->tab, 'nodes' => FieldView::nodes($scope->roots(), $rules, $ctx), 'read_only' => $readOnly];
    }

    private static function month(Input $query): DateTimeImmutable
    {
        $asked = $query->date('month');
        $base = $asked ?? EditorServices::instance()->services()->clock()->today('UTC');

        return Days::date($base->format('Y-m-01'));
    }

    /**
     * @return array{country: string, years: list<int>, bridges: bool}|null
     */
    private static function import(Input $query): ?array
    {
        if (!$query->bool('preview')) {
            return null;
        }
        $years = array_map('intval', $query->list('years', '/^20\d\d$/', 3));

        return ['country' => $query->text('country', 2), 'years' => $years === [] ? [(int) EditorServices::instance()->services()->clock()->today('UTC')->format('Y')] : $years, 'bridges' => $query->bool('bridges')];
    }
}
