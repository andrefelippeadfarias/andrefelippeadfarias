<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

/**
 * The nine steps of the setup wizard and which are done (admin spec 5.1). Progress is derived from
 * facts, never stored, so the wizard cannot disagree with the data. Pure.
 */
final class WizardProgress
{
    public const STEPS = [
        1 => 'welcome',
        2 => 'pms',
        3 => 'pricing',
        4 => 'property',
        5 => 'rooms',
        6 => 'rules',
        7 => 'safety',
        8 => 'shadow_run',
        9 => 'would_do',
    ];

    /** The capability each step needs to act (3.3); "view" only reads. */
    public const CAPABILITY = [1 => 'view', 2 => 'connections', 3 => 'connections', 4 => 'rules', 5 => 'rules', 6 => 'rules', 7 => 'safety', 8 => 'rules', 9 => 'view'];

    /**
     * @param array<string, bool> $facts pms_tested, pricing_tested, has_property, rooms_complete, rules_chosen, safety_confirmed, shadow_run_done
     */
    public function __construct(private readonly array $facts)
    {
    }

    public function done(int $step): bool
    {
        return match ($step) {
            1 => true,
            2 => $this->fact('pms_tested'),
            3 => $this->fact('pricing_tested'),
            4 => $this->fact('has_property'),
            5 => $this->fact('rooms_complete'),
            6 => $this->fact('rules_chosen'),
            7 => $this->fact('safety_confirmed'),
            8 => $this->fact('shadow_run_done'),
            default => false,
        };
    }

    /** The first step not done (9 when every step before it is). */
    public function current(): int
    {
        foreach (array_keys(self::STEPS) as $step) {
            if ($step < 9 && !$this->done($step)) {
                return $step;
            }
        }

        return 9;
    }

    public function complete(): bool
    {
        return $this->current() === 9;
    }

    private function fact(string $name): bool
    {
        return ($this->facts[$name] ?? false) === true;
    }
}
