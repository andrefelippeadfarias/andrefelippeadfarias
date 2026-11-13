<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Application\Rules\RuleDraft;
use Ritmo\Application\Rules\RuleDraftStorage;
use Ritmo\Application\Rules\RuleDiff;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * A person's rules draft as the screens use it: the draft, or a fresh one from the rules in
 * force; the rules a form shows (the draft's, else the rules in force); staging a change; and
 * dropping a draft older than 7 days or without any change left.
 */
final class DraftDesk
{
    public function __construct(private readonly RuleDraftStorage $storage, private readonly RuleSetRepository $ruleSets, private readonly Clock $clock)
    {
    }

    public function current(int $userId): ?RuleDraft
    {
        $draft = $this->storage->get($userId);
        if ($draft !== null && $draft->isExpired($this->clock->now())) {
            $this->storage->clear($userId);

            return null;
        }

        return $draft;
    }

    /**
     * @return array<string, mixed> the rules a screen shows: the draft's, else the rules in force
     */
    public function rules(int $userId): array
    {
        return $this->current($userId)?->rules ?? $this->inForce();
    }

    /**
     * @return array<string, mixed>
     */
    public function inForce(): array
    {
        return ($this->ruleSets->current() ?? RuleSet::defaults())->toArray();
    }

    /**
     * Puts changed rules into the person's draft (started from the rules in force when there is
     * none); a draft that no longer differs from the rules in force is dropped.
     *
     * @param array<string, mixed> $rules
     * @param list<string>         $touched
     */
    public function stage(int $userId, array $rules, array $touched, string $source = 'editor', ?int $restoredFrom = null): void
    {
        $draft = $this->current($userId) ?? $this->fresh($source, $restoredFrom);
        $draft = $draft->with($rules, $touched, $this->clock->now());
        if ($source !== 'editor') {
            $draft = new RuleDraft($draft->baseVersion, $draft->baseHash, $draft->rules, $draft->touched, $draft->updatedAt, $source, $restoredFrom);
        }
        if (RuleDiff::between($this->inForce(), $draft->rules, Schema::ruleSet()) === []) {
            $this->storage->clear($userId);

            return;
        }
        $this->storage->put($userId, $draft);
    }

    public function discard(int $userId): void
    {
        $this->storage->clear($userId);
    }

    /**
     * The number of settings the draft changes from the rules in force.
     */
    public function changes(int $userId): int
    {
        $draft = $this->current($userId);

        return $draft === null ? 0 : count(RuleDiff::between($this->inForce(), $draft->rules, Schema::ruleSet()));
    }

    private function fresh(string $source, ?int $restoredFrom): RuleDraft
    {
        $current = $this->ruleSets->current() ?? RuleSet::defaults();

        return new RuleDraft($this->ruleSets->currentVersion() ?? 0, $current->hash(), $current->toArray(), [], $this->clock->now(), $source, $restoredFrom);
    }
}
