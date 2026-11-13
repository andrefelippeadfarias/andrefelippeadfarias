<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use InvalidArgumentException;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Dashboard\Connections\ConnectionTests;
use Ritmo\Application\Rules\RuleSetEditor;
use Ritmo\Application\Rules\SavedVersion;
use Ritmo\Application\Setup\SetupService;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * The setup wizard's use cases (admin spec 5.1): the facts its progress is derived from, the room
 * mapping saved in one transaction, the choice of rules, and the confirmation of the safety limits
 * by a person allowed to change them. Nothing here talks to an external system.
 */
final class SetupWizard
{
    public const MANAGE_SAFETY = 'ritmo_manage_safety';

    public const NOTE_RULES = 'Setup wizard';

    public const NOTE_SAFETY = 'Setup wizard: safety limits confirmed';

    public function __construct(
        private readonly Database $db,
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly RunRepository $runs,
        private readonly SetupService $setup,
        private readonly ConnectionTests $tests,
        private readonly SetupState $state,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
        private readonly RuleSetEditor $editor,
    ) {
    }

    public function progress(?string $propertyId): WizardProgress
    {
        $property = $propertyId === null ? null : $this->properties->find($propertyId);
        $rooms = $property === null ? [] : $this->roomTypes->forProperty($property->id);
        $shadow = $property !== null && array_filter(
            $this->runs->latest($property->id, 50),
            static fn (array $run): bool => $run['kind'] === 'shadow' && $run['status'] === RunRepository::COMPLETED,
        ) !== [];

        return new WizardProgress([
            'pms_tested' => ($this->tests->last('beds24')['ok'] ?? false) === true,
            'pricing_tested' => ($this->tests->last('pricelabs')['ok'] ?? false) === true,
            'has_property' => $property !== null,
            'rooms_complete' => $rooms !== [] && array_filter($rooms, static fn (RoomType $r): bool => $r->units < 1 || $r->pmsRef === '' || $r->pricingRef === '') === [],
            'rules_chosen' => $this->state->rulesChosen(),
            'safety_confirmed' => $this->state->safetyFingerprint() !== null && $this->state->safetyFingerprint() === SafetyValues::fingerprint($this->rules(), $this->allRooms()),
            'shadow_run_done' => $shadow,
        ]);
    }

    /**
     * Saves every room of the step in one transaction; one invalid row refuses the whole step.
     * The reference minimum (which sets the absolute floor) is a safety value: an existing room
     * keeps its own (step 7 changes it), a new room starts from the minimum typed. Without
     * ritmo_manage_safety a person cannot change the minimum, base or maximum of an existing room,
     * nor add a room to a property that is not in shadow mode; a new room changes the safety
     * fingerprint, so leaving shadow needs step 7 again.
     *
     * @param list<array<string, string>> $rows inputs of SetupService::saveRoomType without reference_min (property_id set by the caller)
     *
     * @throws RoomRowsRefused listing the row and the reason of each refusal
     */
    public function saveRooms(array $rows, int $userId): int
    {
        if ($rows === []) {
            throw new RoomRowsRefused([['row' => 0, 'message' => 'Add at least one room.']]);
        }
        $maySafety = $this->capabilities->userCan($userId, self::MANAGE_SAFETY);

        return $this->db->transaction(function () use ($rows, $maySafety): int {
            $errors = [];
            foreach ($rows as $index => $row) {
                try {
                    $this->roomTypes->save($this->room($row, $maySafety));
                } catch (InvalidArgumentException $refused) {
                    $errors[] = ['row' => $index + 1, 'message' => $refused->getMessage()];
                }
            }
            if ($errors !== []) {
                throw new RoomRowsRefused($errors);
            }

            return count($rows);
        });
    }

    /**
     * Keeps the rules in force (the conservative defaults on a new site) with the weekend nights
     * chosen, as version N+1 noted "Setup wizard", through the rules editor (event, approvals
     * waiting closed). It never writes a price.
     *
     * @param list<int> $weekendDays Monday = 0 ... Sunday = 6
     */
    public function chooseRules(array $weekendDays, int $userId): int
    {
        $days = array_values(array_unique(array_filter($weekendDays, static fn (int $d): bool => $d >= 0 && $d <= 6)));
        sort($days);
        $candidate = SafetyValues::apply($this->rules(), ['calendar.weekend_days' => $days])->toArray();
        $saved = $this->saveRules($candidate, self::NOTE_RULES, $userId, false, 'wizard');
        $this->state->markRulesChosen($this->clock->now(), $userId);

        return $saved->version;
    }

    /**
     * Imports a preset file in the rules step: its rules go through the rules editor (event,
     * approvals waiting closed) and its properties (in shadow mode) and rooms are saved. A preset
     * carries safety limits and reference minimums, so only a person allowed to change safety
     * limits imports one; the values imported still need step 7 before a property leaves shadow.
     *
     * @return array{properties: int, room_types: int, rules_version: int}
     *
     * @throws InvalidArgumentException when the person may not change safety limits
     * @throws \Ritmo\Domain\Config\InvalidConfig listing every error of the file
     */
    public function importPreset(string $json, int $userId): array
    {
        if (!$this->capabilities->userCan($userId, self::MANAGE_SAFETY)) {
            throw new InvalidArgumentException('Only a person allowed to change safety limits can import a preset.');
        }
        $preset = $this->setup->preset($json);

        return $this->db->transaction(function () use ($preset, $userId): array {
            $saved = $this->saveRules($preset->rules->toArray(), 'Imported preset: ' . mb_substr($preset->name, 0, 100), $userId, true, 'import');

            return $this->setup->savePlaces($preset) + ['rules_version' => $saved->version];
        });
    }

    /**
     * Saves the safety values of step 7 and records who confirmed them. The caller checked the
     * typed confirmation (the property id); the capability is checked here again, and the rules
     * go through the rules editor (event, approvals waiting closed).
     *
     * @param array<string, mixed>  $limits         rule-set keys of SafetyValues::RULE_KEYS => value
     * @param array<string, float>  $referenceMins  room code => reference minimum
     *
     * @throws InvalidArgumentException when the person may not change safety limits, or a value is wrong
     */
    public function confirmSafety(string $propertyId, array $limits, array $referenceMins, int $userId): void
    {
        if (!$this->capabilities->userCan($userId, self::MANAGE_SAFETY)) {
            throw new InvalidArgumentException('Only a person allowed to change safety limits can confirm them.');
        }
        $changes = array_intersect_key($limits, array_flip(SafetyValues::RULE_KEYS));
        $this->db->transaction(function () use ($propertyId, $changes, $referenceMins, $userId): void {
            $saved = $this->saveRules(SafetyValues::apply($this->rules(), $changes)->toArray(), self::NOTE_SAFETY, $userId, true, 'wizard');
            foreach ($this->roomTypes->forProperty($propertyId) as $room) {
                $minimum = $referenceMins[$room->code] ?? $room->referenceMin;
                if ($minimum !== $room->referenceMin) {
                    $this->roomTypes->save(new RoomType($room->propertyId, $room->code, $room->name, $room->units, $room->pmsRef, $room->pricingRef, $room->base, $room->min, $room->max, $minimum, $room->tags));
                }
            }
            $this->state->confirmSafety($this->clock->now(), $userId, $saved->version, SafetyValues::fingerprint($this->rules(), $this->allRooms()));
        });
    }

    public function rules(): RuleSet
    {
        return $this->ruleSets->current() ?? RuleSet::defaults();
    }

    /**
     * Rules saved through the editor; a safety change was confirmed by the caller (typed, by a
     * person allowed to change safety limits).
     *
     * @param array<string, mixed> $candidate complete rules
     */
    private function saveRules(array $candidate, string $note, int $userId, bool $safetyConfirmed, string $source): SavedVersion
    {
        $current = $this->ruleSets->current();
        $typed = $safetyConfirmed ? $this->editor->safetyConfirmation($candidate) : null;

        return $this->editor->save($candidate, $this->ruleSets->currentVersion() ?? 0, $current?->hash() ?? '', $userId, $note, $typed, $safetyConfirmed, $source);
    }

    /**
     * One room row, with the reference minimum and the safety checks of saveRooms().
     *
     * @param array<string, string> $row
     *
     * @throws InvalidArgumentException
     */
    private function room(array $row, bool $maySafety): RoomType
    {
        $existing = $this->roomTypes->find((string) ($row['code'] ?? ''));
        $room = $this->setup->roomType(['reference_min' => $existing?->referenceMin ?? ($row['min'] ?? '')] + $row);
        if ($maySafety) {
            return $room;
        }
        if ($existing !== null && [$existing->min, $existing->base, $existing->max] !== [$room->min, $room->base, $room->max]) {
            throw new InvalidArgumentException('Only a person allowed to change safety limits can change the minimum, base or maximum of an existing room.');
        }
        if ($existing === null && $this->properties->find($room->propertyId)?->mode !== PropertyMode::Shadow) {
            throw new InvalidArgumentException('Only a person allowed to change safety limits can add a room to a property that is not in shadow mode.');
        }

        return $room;
    }

    /**
     * @return list<RoomType>
     */
    private function allRooms(): array
    {
        return $this->roomTypes->all();
    }
}
