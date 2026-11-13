<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Tab;
use Ritmo\Admin\Notice;
use Ritmo\Admin\Screens\Page;
use Ritmo\Admin\View;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;

/**
 * The frame of the rules, orders and market screens: the capability (403 otherwise), the tab
 * (a tab the person may not use answers 403 by URL), the property (URL, then the person's last
 * choice, then the first), the notice of the last action and the tabs; then the screen's own
 * template inside it. Renders templates/admin/editors/frame.
 */
final class EditorPage
{
    public const USER_META = 'ritmo_property';

    /**
     * @param list<Property> $properties
     * @param list<Tab>      $tabs the tabs the person may use
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly array $properties,
        public readonly ?Property $property,
        public readonly array $tabs,
        public readonly string $tab,
        private readonly bool $unknownProperty,
    ) {
    }

    /**
     * @param list<Tab> $tabs
     */
    public static function open(string $slug, string $title, string $capability, array $tabs): self
    {
        self::require($capability);
        $query = Input::query();
        $allowed = array_values(array_filter($tabs, static fn (Tab $t): bool => Capability::can($t->capability)));
        $allowedIds = array_map(static fn (Tab $t): string => $t->id, $allowed);
        $asked = $query->text('tab', 32);
        if ($asked !== '' && in_array($asked, array_map(static fn (Tab $t): string => $t->id, $tabs), true) && !in_array($asked, $allowedIds, true)) {
            self::forbid();
        }
        $properties = EditorServices::instance()->services()->properties()->all();
        $wanted = $query->text('property', 64);
        $wanted = $wanted !== '' ? $wanted : (string) get_user_meta(get_current_user_id(), self::USER_META, true);
        $property = null;
        foreach ($properties as $candidate) {
            if ($candidate->id === $wanted) {
                $property = $candidate;
            }
        }

        return new self($slug, $title, $properties, $property ?? ($properties[0] ?? null), $allowed, in_array($asked, $allowedIds, true) ? $asked : ($allowedIds[0] ?? ''), $wanted !== '' && $property === null && $properties !== []);
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
     * The URL of this screen (property and tab kept) with more arguments.
     *
     * @param array<string, string|int> $args
     */
    public function url(array $args = []): string
    {
        return Menu::url($this->slug, $args + array_filter(['property' => $this->property?->id ?? '', 'tab' => $this->tab], static fn (string $v): bool => $v !== ''));
    }

    /**
     * Room code => name of the property shown.
     *
     * @return array<string, string>
     */
    public function roomNames(): array
    {
        $names = [];
        foreach ($this->rooms() as $room) {
            $names[$room->code] = $room->name;
        }

        return $names;
    }

    /**
     * @return list<RoomType>
     */
    public function rooms(): array
    {
        return $this->property === null ? [] : EditorServices::instance()->services()->roomTypes()->forProperty($this->property->id);
    }

    public function currency(): string
    {
        return $this->property?->currency ?? 'BRL';
    }

    /**
     * @param string               $template path under templates/ ("admin/editors/rules-general")
     * @param array<string, mixed> $view
     */
    public function render(string $template, array $view): void
    {
        $notice = Notice::take();
        if ($this->unknownProperty && $this->property !== null) {
            /* translators: %s: name of the property shown instead */
            $notice ??= ['type' => Notice::WARNING, 'message' => sprintf(__('That property does not exist; showing %s.', 'ritmo-revenue'), $this->property->name), 'details' => []];
        }
        View::render('admin/editors/frame', [
            'page' => $this,
            'header' => Page::headerOf($this->slug, $this->title, $this->properties, $this->property, $this->tabs, $this->tab),
            'notice' => $notice,
            'template' => $template,
            'view' => $view,
            'post_url' => admin_url('admin-post.php'),
        ]);
    }
}
