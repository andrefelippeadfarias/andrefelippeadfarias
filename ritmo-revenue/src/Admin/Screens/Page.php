<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Tab;
use Ritmo\Admin\Notice;
use Ritmo\Admin\View;
use Ritmo\Application\Dashboard\StatusStrip;
use Ritmo\Domain\Model\Property;
use Throwable;

/**
 * The frame of every central screen (admin spec 2.2): the capability check, the property chosen
 * (URL, then the user's last choice, then the first), the header with the property picker and the
 * status strip, the tabs the user may use, the notice of the last action; then the screen's own
 * template inside it.
 */
final class Page
{
    public const USER_META = 'ritmo_property';

    /**
     * @param list<Property> $properties
     * @param list<Tab>      $tabs       the tabs the user may use
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly array $properties,
        public readonly ?Property $property,
        public readonly array $tabs,
        public readonly string $tab,
        private readonly ?string $unknownProperty,
    ) {
    }

    /**
     * Checks the capability (403 otherwise) and resolves the property and the tab.
     *
     * @param list<Tab> $tabs
     */
    public static function open(string $slug, string $title, string $capability, array $tabs = []): self
    {
        self::require($capability);
        $query = Input::query();
        $asked = $query->text('tab', 32);
        $allowed = array_values(array_filter($tabs, static fn (Tab $t): bool => Capability::can($t->capability)));
        $tab = '';
        if ($tabs !== []) {
            $known = array_map(static fn (Tab $t): string => $t->id, $tabs);
            if ($asked !== '' && in_array($asked, $known, true) && !in_array($asked, array_map(static fn (Tab $t): string => $t->id, $allowed), true)) {
                self::forbid();
            }
            $tab = in_array($asked, array_map(static fn (Tab $t): string => $t->id, $allowed), true) ? $asked : ($allowed[0]->id ?? '');
        }
        $properties = ScreenServices::instance()->services()->properties()->all();
        [$property, $unknown] = self::choose($properties, $query->text('property', 64));

        return new self($slug, $title, $properties, $property, $allowed, $tab, $unknown);
    }

    public static function require(string $capability): void
    {
        if (!Capability::can($capability)) {
            self::forbid();
        }
    }

    public static function forbid(): never
    {
        wp_die(esc_html__('You are not allowed to see this page.', 'ritmo-revenue'), '', ['response' => 403]);
        exit;
    }

    /**
     * Renders the frame and the screen's template ("admin/screens/<name>").
     *
     * @param array<string, mixed> $view
     */
    public function render(string $template, array $view): void
    {
        $notice = Notice::take();
        if ($this->unknownProperty !== null && $this->property !== null) {
            /* translators: %s: name of the property shown instead */
            $notice ??= ['type' => Notice::WARNING, 'message' => sprintf(__('That property does not exist; showing %s.', 'ritmo-revenue'), $this->property->name), 'details' => []];
        }
        View::render('admin/screens/layout', [
            'page' => $this,
            'header' => $this->header(),
            'notice' => $notice,
            'template' => $template,
            'view' => $view,
        ]);
    }

    /**
     * The URL of this screen with arguments (the property and the tab kept).
     *
     * @param array<string, string|int> $args
     */
    public function url(array $args = []): string
    {
        $base = array_filter(['property' => $this->property?->id ?? '', 'tab' => $this->tab], static fn (string $v): bool => $v !== '');

        return Menu::url($this->slug, $args + $base);
    }

    /**
     * @return array<string, mixed> texts and links of the header, ready to print
     */
    private function header(): array
    {
        return self::headerOf($this->slug, $this->title, $this->properties, $this->property, $this->tabs, $this->tab);
    }

    /**
     * The header of any screen of the plugin (also the editors' frame): title, property picker,
     * status strip, emergency banner and the tabs the user may use.
     *
     * @param list<Property> $properties
     * @param list<Tab>      $tabs
     *
     * @return array<string, mixed>
     */
    public static function headerOf(string $slug, string $title, array $properties, ?Property $property, array $tabs, string $tab): array
    {
        $strip = null;
        $error = null;
        if ($property !== null) {
            try {
                $strip = ScreenServices::instance()->status()->forProperty($property->id);
            } catch (Throwable $failure) {
                $error = $failure;
            }
        }

        return [
            /* translators: %s: name of the screen */
            'h1' => sprintf(__('Ritmo Revenue — %s', 'ritmo-revenue'), $title),
            'picker' => count($properties) > 1 ? array_map(static fn (Property $p): array => ['id' => $p->id, 'name' => $p->name], $properties) : [],
            'selected' => $property?->id ?? '',
            'strip' => $strip === null ? [] : self::strip($strip, $property?->timezone ?? 'UTC'),
            'emergency' => $strip?->emergency ?? ScreenServices::instance()->services()->emergencySwitch()->isEngaged(),
            'can_release' => Capability::can(Capability::MANAGE_CONNECTIONS) || Capability::can(Capability::APPROVE),
            'strip_failed' => $error !== null,
            'tabs' => array_map(static fn (Tab $t): array => ['id' => $t->id, 'title' => $t->title, 'url' => Menu::url($slug, array_filter(['tab' => $t->id, 'property' => $property?->id ?? ''])), 'current' => $t->id === $tab], $tabs),
            'operations_url' => Menu::url(Menu::OPERATIONS, ['property' => $property?->id ?? '']),
        ];
    }

    /**
     * @return list<array{text: string, url: string, tone: string, icon: string, hook: string}>
     */
    private static function strip(StatusStrip $strip, string $timezone): array
    {
        $items = [[
            /* translators: %s: Shadow, Approval or Autonomous */
            'text' => sprintf(__('Mode: %s', 'ritmo-revenue'), Labels::mode($strip->mode)),
            'url' => Menu::url(Menu::OPERATIONS, ['property' => $strip->propertyId]),
            'tone' => $strip->mode === 'shadow' ? 'neutral' : 'info',
            'icon' => 'dashicons-visibility',
            'hook' => 'mode-badge',
        ]];
        if ($strip->paused) {
            $items[] = [
                'text' => $strip->pausedUntil === null ? __('Runs paused', 'ritmo-revenue')
                    /* translators: %s: time the pause ends */
                    : sprintf(__('Runs paused until %s', 'ritmo-revenue'), NightDate::instant($strip->pausedUntil, $timezone)),
                'url' => Menu::url(Menu::OPERATIONS, ['property' => $strip->propertyId]),
                'tone' => 'attention',
                'icon' => 'dashicons-controls-pause',
                'hook' => 'pause',
            ];
        }
        if ($strip->waitingRequests > 0) {
            $items[] = [
                'text' => sprintf(
                    /* translators: 1: nights waiting, 2: time the plans expire */
                    _n('%1$s night waiting for approval · valid until %2$s', '%1$s nights waiting for approval · valid until %2$s', $strip->waitingEntries, 'ritmo-revenue'),
                    number_format_i18n($strip->waitingEntries),
                    $strip->waitingExpires === null ? '—' : NightDate::time($strip->waitingExpires, $timezone)
                ),
                'url' => Menu::url(Menu::APPROVALS, ['property' => $strip->propertyId]),
                'tone' => 'attention',
                'icon' => 'dashicons-yes-alt',
                'hook' => 'approvals-waiting',
            ];
        }
        if ($strip->lastRun !== null) {
            $items[] = [
                'text' => sprintf(
                    /* translators: 1: kind of run, 2: time, 3: status in words */
                    __('Last run: %1$s at %2$s, %3$s', 'ritmo-revenue'),
                    Labels::runKind($strip->lastRun['kind']),
                    NightDate::time($strip->lastRun['started_at'], $timezone),
                    Labels::runStatus($strip->lastRun['status'])
                ),
                'url' => Menu::url(Menu::SETTINGS, ['tab' => 'diagnostics', 'run' => $strip->lastRun['id'], 'property' => $strip->propertyId]),
                'tone' => $strip->lastRun['status'] === 'failed' ? 'critical' : 'good',
                'icon' => $strip->lastRun['status'] === 'failed' ? 'dashicons-warning' : 'dashicons-clock',
                'hook' => 'last-run',
            ];
        }

        return $items;
    }

    /**
     * @param list<Property> $properties
     *
     * @return array{0: Property|null, 1: string|null} the property shown and the id asked for when it was unknown
     */
    private static function choose(array $properties, string $asked): array
    {
        $byId = [];
        foreach ($properties as $property) {
            $byId[$property->id] = $property;
        }
        if ($asked !== '' && isset($byId[$asked])) {
            return [$byId[$asked], null];
        }
        $remembered = (string) get_user_meta(get_current_user_id(), self::USER_META, true);
        $fallback = $byId[$remembered] ?? ($properties[0] ?? null);

        return [$fallback, $asked !== '' ? $asked : null];
    }
}
