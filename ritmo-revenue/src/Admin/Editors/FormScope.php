<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

/**
 * The part of the rules one form edits: a section of the general tab, or a whole other tab.
 * A field belongs to the scope when RulesLayout places it there; containers are walked through
 * whatever their placement, so a tab can edit one field of a section placed elsewhere.
 */
final class FormScope
{
    private const ROOTS = [
        RulesLayout::SAFETY => ['safety', 'floors', 'write', 'modes', 'quota'],
        RulesLayout::CALENDAR => ['calendar', 'stay'],
        RulesLayout::RELATIONS => ['relations', 'price_bands'],
    ];

    private function __construct(public readonly string $tab, public readonly string $section)
    {
    }

    public static function section(string $section): self
    {
        return new self(RulesLayout::GENERAL, $section);
    }

    public static function tab(string $tab): self
    {
        return new self($tab, $tab);
    }

    /**
     * @return list<string> the top-level keys the scope walks
     */
    public function roots(): array
    {
        return $this->tab === RulesLayout::GENERAL ? RulesLayout::roots($this->section) : (self::ROOTS[$this->tab] ?? []);
    }

    public function holds(string $key): bool
    {
        $placement = RulesLayout::place($key);

        return $placement->tab === $this->tab && ($this->tab !== RulesLayout::GENERAL || $placement->section === $this->section);
    }

    public function id(): string
    {
        return $this->tab === RulesLayout::GENERAL ? $this->section : $this->tab;
    }
}
