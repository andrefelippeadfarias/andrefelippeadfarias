<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;

/**
 * The validator's check of the PMS changes of a run (spec 06, 3.5). An error refuses that change
 * only, except pms_too_many, which refuses them all. An undo is exempt from pms_rule_mismatch and
 * pms_orphan_proof, never from the others (3.9).
 */
final class PmsChangeCheck
{
    public const ROOM_UNKNOWN = 'pms_room_unknown';
    public const FIELD_FORBIDDEN = 'pms_field_forbidden';
    public const STAY_RANGE = 'pms_stay_range';
    public const BLACKOUT = 'pms_blackout';
    public const PAST_NIGHT = 'pms_past_night';
    public const FROZEN = 'pms_frozen';
    public const ORPHAN_PROOF = 'pms_orphan_proof';
    public const RULE_MISMATCH = 'pms_rule_mismatch';
    public const UNPAIRED = 'pms_unpaired';
    public const DUPLICATE = 'pms_duplicate';
    public const TOO_MANY = 'pms_too_many';
    public const REVERTED_BY_PUSH = 'pms_reverted_by_push';

    /** Longest maximum stay the PMS takes from a stay rule. */
    public const MAX_STAY = 30;

    private const STAY_FIELDS = [ChangeField::PMS_MIN_STAY, ChangeField::PMS_MAX_STAY, ChangeField::PMS_RESTRICTION];
    private const ORPHAN_REASONS = ['orphan_night', 'closed_day_orphan', 'orphan_side_closed_day_sold_out', 'orphan_side_previous_sold_out', 'orphan_side_previous_past'];

    /**
     * @param list<PmsChangeEntry> $entries
     */
    public function check(array $entries, PmsCheckContext $context): PmsCheckResult
    {
        if (count($entries) > $context->config->maxChangesPerRun) {
            return new PmsCheckResult([], array_map(static fn (PmsChangeEntry $e): array => ['entry' => $e, 'code' => self::TOO_MANY], $entries), true);
        }
        $accepted = $refused = $seen = [];
        foreach ($entries as $entry) {
            $code = $this->refusal($entry, $context) ?? self::duplicate($entry, $seen);
            if ($code === null) {
                $accepted[] = $entry;
            } else {
                $refused[] = ['entry' => $entry, 'code' => $code];
            }
        }

        return new PmsCheckResult($accepted, $refused, false);
    }

    private function refusal(PmsChangeEntry $entry, PmsCheckContext $context): ?string
    {
        $room = $context->rooms[$entry->roomCode] ?? null;
        $key = $entry->roomCode . '|' . Days::key($entry->night);

        return match (true) {
            $room === null || $room->pmsRef === '' => self::ROOM_UNKNOWN,
            !self::fieldsAllowed($entry, $context) => self::FIELD_FORBIDDEN,
            !self::stayInRange($entry, $context) => self::STAY_RANGE,
            self::touchesBlackout($entry) => self::BLACKOUT,
            $entry->night < $context->today => self::PAST_NIGHT,
            isset($context->frozen[$key]) => self::FROZEN,
            !$entry->isUndo() && !self::proofHolds($entry, $context) => self::ORPHAN_PROOF,
            !$entry->isUndo() && $entry->orderId === null && !isset($context->ruleTargets[$entry->identity()]) => self::RULE_MISMATCH,
            $entry->needsPricingStep() && ($entry->pairedItem === null || !isset($context->acceptedPricing[$entry->pairedItem])) => self::UNPAIRED,
            self::reverted($entry, $context) => self::REVERTED_BY_PUSH,
            default => null,
        };
    }

    /**
     * Stay fields always; availability and channel limits only from an owner order that allows
     * them, or an undo.
     */
    private static function fieldsAllowed(PmsChangeEntry $entry, PmsCheckContext $context): bool
    {
        $mayAll = $entry->isUndo() || ($entry->orderId !== null && isset($context->allowingOrders[$entry->orderId]));
        foreach (array_keys($entry->target->all()) as $field) {
            if (!in_array($field, self::STAY_FIELDS, true) && !$mayAll) {
                return false;
            }
        }

        return true;
    }

    private static function stayInRange(PmsChangeEntry $entry, PmsCheckContext $context): bool
    {
        $read = $context->inventory[$entry->roomCode][Days::key($entry->night)] ?? null;
        $min = $entry->minStay() ?? $read?->minStay;
        $max = $entry->maxStay() ?? $read?->maxStay;
        if ($entry->minStay() !== null && ($entry->minStay() < 1 || $entry->minStay() > $context->maxMinStay)) {
            return false;
        }
        if ($entry->maxStay() !== null && ($entry->maxStay() < 1 || $entry->maxStay() > self::MAX_STAY)) {
            return false;
        }

        return $min === null || $max === null || $min <= $max;
    }

    private static function touchesBlackout(PmsChangeEntry $entry): bool
    {
        return $entry->restriction() === Restriction::Blackout
            || $entry->before->get(ChangeField::PMS_RESTRICTION) === Restriction::Blackout->value;
    }

    /**
     * A change made for an orphan night or an orphan side carries a proof read in this run, young
     * enough, that still holds on this run's inventory (invariant 13).
     */
    private static function proofHolds(PmsChangeEntry $entry, PmsCheckContext $context): bool
    {
        $proof = $entry->proof;
        if ($proof === null) {
            return !in_array($entry->reason, self::ORPHAN_REASONS, true);
        }
        if ($proof->source !== OrphanProof::SOURCE_PMS || $proof->readAt === null) {
            return false;
        }
        $age = $context->now->getTimestamp() - $proof->readAt->getTimestamp();
        if ($age < 0 || $age > $context->proofMaxAgeHours * 3600) {
            return false;
        }
        $nights = $context->inventory[$entry->roomCode] ?? [];
        $night = $nights[Days::key($entry->night)] ?? null;
        $neighbour = $nights[Days::key(Days::add($entry->night, $proof->neighbour === OrphanProof::NEXT ? 1 : -1))] ?? null;
        $free = $night !== null && $night->available > 0;
        $closed = $proof->previousPast !== null || ($neighbour !== null && $neighbour->available <= 0);

        return $free && $closed;
    }

    private static function reverted(PmsChangeEntry $entry, PmsCheckContext $context): bool
    {
        foreach (array_keys($entry->target->all()) as $field) {
            if ($context->reverts->count($entry->roomCode, $entry->night, $field) >= $context->config->revertLimit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $seen field keys of the changes accepted so far
     */
    private static function duplicate(PmsChangeEntry $entry, array &$seen): ?string
    {
        foreach ($entry->fieldKeys() as $key) {
            if (isset($seen[$key])) {
                return self::DUPLICATE;
            }
        }
        foreach ($entry->fieldKeys() as $key) {
            $seen[$key] = true;
        }

        return null;
    }
}
