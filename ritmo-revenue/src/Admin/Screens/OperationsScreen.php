<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Tab;
use Ritmo\Admin\RefusalText;
use Ritmo\Admin\Reports\RunLineText;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Throwable;

/**
 * Operations (admin spec 5.16): the mode of the property with what is still missing to go up, run
 * now, pause and resume, the emergency switch; the schedule of the runs; and who did what.
 * Approvals and the change log have their own screens.
 */
final class OperationsScreen
{
    /** The conditions of the approval mode shown as a checklist, in this order. */
    private const APPROVAL_CHECKLIST = ['safety_confirmed', 'shadow_runs', 'connection_untested'];

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
            new Tab('modes', __('Mode and switch', 'ritmo-revenue'), $v),
            new Tab('schedule', __('Schedule', 'ritmo-revenue'), $v),
            new Tab('events', __('Who did what', 'ritmo-revenue'), $v),
        ];
    }

    public static function render(): void
    {
        $page = Page::open(Menu::OPERATIONS, __('Operations', 'ritmo-revenue'), Capability::VIEW, self::tabs());
        $property = $page->property;
        if ($property === null) {
            $page->render('operations', ['error' => null, 'empty' => true, 'tab' => $page->tab]);

            return;
        }
        try {
            $view = match ($page->tab) {
                'schedule' => self::schedule($property),
                'events' => self::events($property),
                default => self::modes($property),
            };
        } catch (Throwable $failure) {
            $page->render('operations', ['error' => ErrorText::from($failure), 'empty' => false, 'tab' => $page->tab]);

            return;
        }
        $page->render('operations', ['error' => null, 'empty' => false, 'tab' => $page->tab, 'property_id' => $property->id] + $view);
    }

    /**
     * @return array<string, mixed>
     */
    private static function modes(Property $property): array
    {
        $services = ScreenServices::instance()->services();
        $stack = $services->stack();
        $rules = $stack->rules();
        $engaged = $services->emergencySwitch()->isEngaged();
        $state = $stack->states()->get($property->id);
        $now = $services->clock()->now();
        $last = $stack->runs()->latest($property->id, 1)[0] ?? null;

        return [
            'mode' => ['value' => $property->mode->value, 'label' => Labels::mode($property->mode->value), 'sentence' => Labels::modeSentence($property->mode->value)],
            'checklist' => self::checklist($property, $stack->preconditions()->toApproval($property, $rules->modes()), $stack->preconditions()->toAutonomous($property)),
            'autonomous_enabled' => $rules->modes()->autonomousEnabled,
            'paused' => $state->isPausedAt($now),
            'paused_text' => !$state->isPausedAt($now) ? '' : ($state->pausedUntil === null
                ? __('Scheduled runs are paused until someone resumes them.', 'ritmo-revenue')
                /* translators: %s: date and time */
                : sprintf(__('Scheduled runs are paused until %s.', 'ritmo-revenue'), NightDate::instant($state->pausedUntil, $property->timezone))),
            'last_run' => $last === null ? null : [
                /* translators: 1: kind of run, 2: date and time, 3: status in words */
                'title' => sprintf(__('%1$s of %2$s: %3$s', 'ritmo-revenue'), Labels::runKind((string) $last['kind']), NightDate::instant($last['started_at'], $property->timezone), Labels::runStatus((string) $last['status'])),
                'lines' => RunLineText::ofSummary(is_array($last['summary'] ?? null) ? $last['summary'] : [])['lines'],
            ],
            'switch' => [
                'engaged' => $engaged,
                'by_constant' => $services->emergencySwitch()->isEngagedByConstant(),
                'may_engage' => OperationsActions::maySwitch(true),
                'may_release' => OperationsActions::maySwitch(false),
            ],
            'can_run' => Capability::can(Capability::MANAGE_RULES),
            'can_change_mode' => Capability::can(Capability::APPROVE) || Capability::can(Capability::MANAGE_RULES) || Capability::can(Capability::MANAGE_CONNECTIONS),
            'approvals_url' => Menu::url(Menu::APPROVALS, ['property' => $property->id]),
            'changes_url' => Menu::url(Menu::CHANGES, ['property' => $property->id]),
        ];
    }

    /**
     * What the next mode still needs, as a checklist in words ("1 of 3 done").
     *
     * @param list<array{code: string, params: array<string, int|string>, text: string}> $toApproval
     * @param list<array{code: string, params: array<string, int|string>, text: string}> $toAutonomous
     *
     * @return array{target: string, done: int, total: int, items: list<array{text: string, met: bool}>, blockers: list<string>}
     */
    private static function checklist(Property $property, array $toApproval, array $toAutonomous): array
    {
        if ($property->mode !== PropertyMode::Shadow) {
            $blockers = array_map(static fn (array $c): string => RefusalText::condition($c['code'], $c['params']), $toAutonomous);

            return ['target' => Labels::mode(PropertyMode::Auto->value), 'done' => 0, 'total' => 0, 'items' => [], 'blockers' => $property->mode === PropertyMode::Approval ? $blockers : []];
        }
        $unmet = [];
        foreach ($toApproval as $condition) {
            $unmet[$condition['code']][] = $condition;
        }
        $items = [
            ['text' => __('The safety limits were confirmed by a person allowed to change them.', 'ritmo-revenue'), 'met' => !isset($unmet['safety_confirmed'])],
            ['text' => isset($unmet['shadow_runs']) ? RefusalText::condition('shadow_runs', $unmet['shadow_runs'][0]['params']) : __('Recent runs completed in shadow mode with a plan.', 'ritmo-revenue'), 'met' => !isset($unmet['shadow_runs']) && !isset($unmet['shadow_run_old'])],
            ['text' => __('Both connections passed a test within 24 hours.', 'ritmo-revenue'), 'met' => !isset($unmet['connection_untested'])],
        ];
        $blockers = [];
        foreach ($toApproval as $condition) {
            if (!in_array($condition['code'], self::APPROVAL_CHECKLIST, true)) {
                $blockers[] = RefusalText::condition($condition['code'], $condition['params']);
            }
        }

        return [
            'target' => Labels::mode(PropertyMode::Approval->value),
            'done' => count(array_filter($items, static fn (array $i): bool => $i['met'])),
            'total' => count($items),
            'items' => $items,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schedule(Property $property): array
    {
        $services = ScreenServices::instance();
        $schedule = $services->services()->stack()->rules()->schedule();
        $times = [];
        foreach ($schedule->times() as [$kind, $time]) {
            $times[] = ['kind' => Labels::runKind($kind->value), 'time' => $time->toString()];
        }
        $next = $services->overview()->nextRuns($property);

        return [
            'times' => $times,
            /* translators: %s: date and time */
            'next_full' => sprintf(__('Next full run: %s', 'ritmo-revenue'), NightDate::instant($next['full'], $property->timezone)),
            'timezone' => $property->timezone,
            'rules_url' => Menu::url(Menu::RULES, ['property' => $property->id, 'tab' => 'general', 'section' => 'runs']),
            'can_edit' => Capability::can(Capability::MANAGE_RULES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function events(Property $property): array
    {
        $repository = ScreenServices::instance()->services()->stack()->events();
        $events = [...$repository->latest($property->id, null, 50), ...$repository->latest('', null, 20)];
        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return ['events' => array_map(static fn (array $e): array => [
            'when' => NightDate::instant($e['at'], $property->timezone),
            'what' => OperationsText::event($e),
            'who' => OperationsText::actor($e['actor']),
            'why' => in_array($e['kind'], ['pause', 'mode'], true) && str_starts_with($e['actor'], 'user:') ? $e['reason'] : '',
        ], array_slice($events, 0, 50))];
    }
}
