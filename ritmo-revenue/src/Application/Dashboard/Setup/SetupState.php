<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use DateTimeImmutable;
use Ritmo\Contracts\KeyValueStore;

/**
 * The facts of the setup wizard that are not in the tables (option "setup"): when a person chose
 * the rules, and who confirmed the safety limits, when, at which rules version and with which
 * safety values (their fingerprint), so a later change of a limit opens step 7 again.
 */
final class SetupState
{
    public const KEY = 'setup';

    public function __construct(private readonly KeyValueStore $settings)
    {
    }

    public function rulesChosen(): bool
    {
        return is_string($this->all()['rules_chosen_at'] ?? null);
    }

    public function safetyFingerprint(): ?string
    {
        $value = $this->all()['safety_fingerprint'] ?? null;

        return is_string($value) && is_string($this->all()['safety_confirmed_at'] ?? null) ? $value : null;
    }

    public function markRulesChosen(DateTimeImmutable $at, int $userId): void
    {
        $this->settings->set(self::KEY, ['rules_chosen_at' => $at->format(DATE_ATOM), 'rules_chosen_by' => $userId] + $this->all());
    }

    public function confirmSafety(DateTimeImmutable $at, int $userId, ?int $version, string $fingerprint): void
    {
        $this->settings->set(self::KEY, [
            'safety_confirmed_at' => $at->format(DATE_ATOM),
            'safety_confirmed_by' => $userId,
            'safety_version' => $version,
            'safety_fingerprint' => $fingerprint,
        ] + $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $value = $this->settings->get(self::KEY, []);

        return is_array($value) ? $value : [];
    }
}
