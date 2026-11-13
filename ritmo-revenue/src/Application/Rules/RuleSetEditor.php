<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * Saves candidate rules as a new, immutable version (spec 05, 5.5.1 step 3). It never writes a
 * price and never starts a run: the version applies from the next run.
 *
 * In order: the note is checked for guest data; when the rules in force moved since the draft
 * started, the draft is merged on top of them (a value both changed is a conflict and refuses
 * the save); the rules are checked in full; a change of a safety field needs
 * ritmo_manage_safety and the typed new values; the version is saved, an event is recorded for
 * every property (who, when, from and to, source, safety confirmation) and every request
 * waiting for approval ends with the reason "rules_changed".
 */
final class RuleSetEditor
{
    public const RULES_CHANGED = 'rules_changed';

    public const NOTE_MAX = 190;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly RuleSetRepository $ruleSets,
        private readonly PropertyRepository $properties,
        private readonly EditorEventRepository $events,
        private readonly RuleChecks $checks,
        private readonly PendingApprovals $approvals,
    ) {
    }

    /**
     * @param array<string, mixed> $candidate       complete rules
     * @param string|null          $typed           the typed confirmation of safety changes
     * @param bool                 $mayChangeSafety the person holds ritmo_manage_safety
     * @param string               $source          editor, wizard, restore or import
     *
     * @throws RulesRefused  on a conflict, a safety change refused or a note refused
     * @throws InvalidConfig with every error of the rules
     */
    public function save(array $candidate, int $baseVersion, string $baseHash, int $userId, string $note, ?string $typed, bool $mayChangeSafety, string $source = 'editor', ?int $restoredFrom = null): SavedVersion
    {
        $problem = PrivacyCheck::problem($note, self::NOTE_MAX);
        if ($problem !== null) {
            throw new RulesRefused(RulesRefused::NOTE, ['problem' => $problem]);
        }
        $current = $this->ruleSets->current();
        $currentVersion = $this->ruleSets->currentVersion();
        $inForce = $current?->toArray() ?? RuleSet::defaults()->toArray();
        if ($currentVersion !== null && $currentVersion !== $baseVersion) {
            $candidate = $this->merged($candidate, $baseVersion, $baseHash, $inForce);
        }
        $errors = $this->checks->errors($candidate);
        if ($errors !== []) {
            throw new InvalidConfig('rule set', $errors);
        }
        $changes = RuleDiff::between($inForce, $candidate, Schema::ruleSet());
        $safety = SafetyFields::of($changes);
        if ($safety !== []) {
            if (!$mayChangeSafety) {
                throw new RulesRefused(RulesRefused::SAFETY_NOT_ALLOWED);
            }
            $expected = SafetyFields::confirmation($safety);
            if (trim((string) $typed) !== $expected) {
                throw new RulesRefused(RulesRefused::CONFIRMATION, ['expected' => $expected]);
            }
        }
        $rules = RuleSet::fromArray($candidate);

        return $this->db->transaction(function () use ($rules, $note, $userId, $currentVersion, $changes, $safety, $source, $restoredFrom): SavedVersion {
            $version = $this->ruleSets->save($rules, $note, $userId);
            if ($version === $currentVersion) {
                return new SavedVersion($version, $currentVersion, 0, 0, true);
            }
            $closed = 0;
            $details = [
                'changes' => count($changes),
                'source' => $source,
                'restored_from' => $restoredFrom,
                'safety' => array_map(static fn (FieldChange $c): string => $c->path, $safety),
                'confirmed' => $safety === [] ? '' : SafetyFields::confirmation($safety),
            ];
            foreach ($this->propertyIds() as $propertyId) {
                $this->events->record($propertyId, EditorEventRepository::RULES_VERSION, (string) ($currentVersion ?? ''), (string) $version, $note === '' ? 'Rules saved' : $note, 'user:' . $userId, $this->clock->now(), $details);
                $closed += $propertyId === '' ? 0 : $this->approvals->expireAll($propertyId, self::RULES_CHANGED);
            }

            return new SavedVersion($version, $currentVersion, count($changes), $closed, false);
        });
    }

    /**
     * The nights waiting for approval that saving would close, per property.
     *
     * @return array<string, int>
     */
    public function waitingNights(): array
    {
        $waiting = [];
        foreach ($this->propertyIds() as $propertyId) {
            $nights = $propertyId === '' ? 0 : $this->approvals->waitingNights($propertyId);
            if ($nights > 0) {
                $waiting[$propertyId] = $nights;
            }
        }

        return $waiting;
    }

    /**
     * The draft merged on top of the rules in force.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $inForce
     *
     * @return array<string, mixed>
     *
     * @throws RulesRefused on a conflict, or when the base version cannot be read
     */
    private function merged(array $candidate, int $baseVersion, string $baseHash, array $inForce): array
    {
        try {
            $base = $this->ruleSets->find($baseVersion);
        } catch (InvalidConfig) {
            $base = null;
        }
        if ($base === null || $base->hash() !== $baseHash) {
            throw new RulesRefused(RulesRefused::UNKNOWN_VERSION, ['version' => $baseVersion]);
        }
        $merge = RuleMerge::merge($base->toArray(), $candidate, $inForce, Schema::ruleSet());
        if ($merge['conflicts'] !== []) {
            throw new RulesRefused(RulesRefused::CONFLICT, ['conflicts' => $merge['conflicts']]);
        }

        return $merge['rules'];
    }

    /**
     * Every property id; one empty id when the site has none yet (the event is still recorded).
     *
     * @return list<string>
     */
    private function propertyIds(): array
    {
        $ids = array_map(static fn ($p): string => $p->id, $this->properties->all());

        return $ids === [] ? [''] : $ids;
    }
}
