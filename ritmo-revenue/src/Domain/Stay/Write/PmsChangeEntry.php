<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\ChangeTarget;
use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\PmsRestore;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;

/**
 * One PMS change of a run as it is planned, checked, sealed and approved: the room night, the PMS
 * fields to set and the ones read before, why, whether the pricing system pushes the field too
 * and how that is covered (a paired pricing item of this run, or the pricing calendar already
 * holding the value), and the undo or owner order behind it. Never a price, never a booking.
 */
final class PmsChangeEntry
{
    public readonly DateTimeImmutable $night;

    /**
     * @param list<int> $undoOf change records an undo writes back
     *
     * @throws InvalidArgumentException on fields of another system or none
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly NightFields $target,
        public readonly NightFields $before,
        public readonly string $reason,
        public readonly bool $alsoPricing = false,
        public readonly ?OrphanProof $proof = null,
        public readonly bool $pricingHolds = false,
        public readonly ?string $pairedItem = null,
        public readonly array $undoOf = [],
        public readonly ?string $orderId = null,
    ) {
        if ($target->isEmpty() || !$target->of(Provider::Pms)->equals($target)) {
            throw new InvalidArgumentException('A PMS change sets PMS fields only.');
        }
        $this->night = Days::normalize($night);
    }

    /**
     * A change of the stay rules (M2 audit) meant for the PMS.
     *
     * @throws InvalidArgumentException on a change meant for the pricing system
     */
    public static function fromStay(StayChange|ArrivalDepartureChange $change): self
    {
        if ($change->target !== ChangeTarget::Pms) {
            throw new InvalidArgumentException('Only changes meant for the PMS become PMS entries.');
        }
        if ($change instanceof StayChange) {
            $target = new NightFields([ChangeField::PMS_MIN_STAY => $change->minStay, ChangeField::PMS_MAX_STAY => $change->maxStay]);
            $before = new NightFields([ChangeField::PMS_MIN_STAY => $change->minStayBefore, ChangeField::PMS_MAX_STAY => $change->maxStayBefore]);
        } else {
            $target = new NightFields([ChangeField::PMS_RESTRICTION => $change->after?->value]);
            $before = new NightFields([ChangeField::PMS_RESTRICTION => $change->before?->value]);
        }

        return new self($change->roomCode, $change->night, $target, $before, $change->reason->value, $change->alsoPricing, $change->proof);
    }

    /**
     * An undo of PMS fields the plugin wrote (reason UNDO).
     */
    public static function fromRestore(PmsRestore $restore, NightFields $before): self
    {
        return new self($restore->roomCode, $restore->night, $restore->fields, $before->of(Provider::Pms), 'undo', false, null, false, null, $restore->undoOf);
    }

    public function with(bool $pricingHolds, ?string $pairedItem): self
    {
        return new self($this->roomCode, $this->night, $this->target, $this->before, $this->reason, $this->alsoPricing, $this->proof, $pricingHolds, $pairedItem, $this->undoOf, $this->orderId);
    }

    public function isUndo(): bool
    {
        return $this->undoOf !== [];
    }

    /**
     * True when the pricing system's push carries this value and nothing in this run covers it yet.
     */
    public function needsPricingStep(): bool
    {
        return $this->alsoPricing && !$this->pricingHolds;
    }

    public function minStay(): ?int
    {
        $value = $this->target->get(ChangeField::PMS_MIN_STAY);

        return is_int($value) ? $value : null;
    }

    public function maxStay(): ?int
    {
        $value = $this->target->get(ChangeField::PMS_MAX_STAY);

        return is_int($value) ? $value : null;
    }

    public function restriction(): ?Restriction
    {
        $value = $this->target->get(ChangeField::PMS_RESTRICTION);

        return is_string($value) ? Restriction::tryFrom($value) : null;
    }

    /**
     * One key per room, night and field: "room|night|field".
     *
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return array_map(fn (string $field): string => $this->roomCode . '|' . Days::key($this->night) . '|' . $field, array_keys($this->target->all()));
    }

    /**
     * Room, night and the values set: two entries with the same identity are the same change.
     */
    public function identity(): string
    {
        return $this->roomCode . '|' . Days::key($this->night) . '|' . $this->target->fingerprint();
    }
}
