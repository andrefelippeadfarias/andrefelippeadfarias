<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use InvalidArgumentException;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Dashboard\Connections\ConnectionTests;
use Ritmo\Application\Setup\SetupService;
use Ritmo\Domain\Config\RuleSet;
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
     *
     * @param list<array<string, string>> $rows inputs of SetupService::saveRoomType (property_id set by the caller)
     *
     * @throws RoomRowsRefused listing the row and the reason of each refusal
     */
    public function saveRooms(array $rows): int
    {
        if ($rows === []) {
            throw new RoomRowsRefused([['row' => 0, 'message' => 'Add at least one room.']]);
        }

        return $this->db->transaction(function () use ($rows): int {
            $errors = [];
            foreach ($rows as $index => $row) {
                try {
                    $this->setup->saveRoomType($row);
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
     * chosen, as version N+1 noted "Setup wizard". It never writes a price.
     *
     * @param list<int> $weekendDays Monday = 0 ... Sunday = 6
     */
    public function chooseRules(array $weekendDays, int $userId): int
    {
        $days = array_values(array_unique(array_filter($weekendDays, static fn (int $d): bool => $d >= 0 && $d <= 6)));
        sort($days);
        $rules = SafetyValues::apply($this->rules(), ['calendar.weekend_days' => $days]);
        $version = $this->ruleSets->save($rules, self::NOTE_RULES, $userId);
        $this->state->markRulesChosen($this->clock->now(), $userId);

        return $version;
    }

    /**
     * Saves the safety values of step 7 and records who confirmed them. The caller checked the
     * typed confirmation; the capability is checked here again.
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
            $rules = SafetyValues::apply($this->rules(), $changes);
            $version = $this->ruleSets->save($rules, self::NOTE_SAFETY, $userId);
            foreach ($this->roomTypes->forProperty($propertyId) as $room) {
                $minimum = $referenceMins[$room->code] ?? $room->referenceMin;
                if ($minimum !== $room->referenceMin) {
                    $this->roomTypes->save(new RoomType($room->propertyId, $room->code, $room->name, $room->units, $room->pmsRef, $room->pricingRef, $room->base, $room->min, $room->max, $minimum, $room->tags));
                }
            }
            $this->state->confirmSafety($this->clock->now(), $userId, $version, SafetyValues::fingerprint($rules, $this->allRooms()));
        });
    }

    public function rules(): RuleSet
    {
        return $this->ruleSets->current() ?? RuleSet::defaults();
    }

    /**
     * @return list<RoomType>
     */
    private function allRooms(): array
    {
        return $this->roomTypes->all();
    }
}
