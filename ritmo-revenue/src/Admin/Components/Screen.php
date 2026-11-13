<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Closure;

/**
 * A screen of the menu (admin spec 4.1): its slug, title, capability, tabs and the callable that
 * renders it. The integration registers every area's screens in the order of the menu.
 */
final class Screen
{
    /**
     * @param list<Tab>         $tabs
     * @param Closure(): void   $render
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $capability,
        public readonly array $tabs,
        public readonly Closure $render,
    ) {
    }

    /**
     * The tab asked for when the user may use it, else the first tab the user may use.
     */
    public function tab(string $asked): ?Tab
    {
        $allowed = array_values(array_filter($this->tabs, static fn (Tab $t): bool => Capability::can($t->capability)));
        foreach ($allowed as $tab) {
            if ($tab->id === $asked) {
                return $tab;
            }
        }

        return $asked === '' ? ($allowed[0] ?? null) : null;
    }
}
