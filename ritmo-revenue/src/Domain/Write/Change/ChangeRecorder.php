<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

use DateTimeImmutable;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Support\Num;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * Turns a write confirmed STORED into change-log rows: one per field whose value changed between
 * what the night held when the run collected and what the read-back shows, all carrying the undo
 * of the night. On the PMS only the fields the plugin sent count (availability also moves with
 * bookings). A write that changed nothing leaves no row.
 *
 * The rows are written at the "stored" level, never from the API response alone (decision D4).
 */
final class ChangeRecorder
{
    private const LISTING_FIELDS = [
        'min' => ChangeField::LISTING_MIN,
        'base' => ChangeField::LISTING_BASE,
        'max' => ChangeField::LISTING_MAX,
    ];

    /**
     * @param string $actor ChangeRecord::ACTOR_AUTONOMOUS, or the approving user ("user:<id>")
     */
    public function __construct(
        private readonly string $propertyId,
        private readonly string $actor,
        private readonly ?int $runId = null,
    ) {
    }

    /**
     * @param NightFields $observed the night read back after the write
     * @param float       $base     the listing base the percentages were written over
     *
     * @return list<ChangeRecord>
     */
    public function night(WrittenNight $written, NightFields $observed, float $base, DateTimeImmutable $at, string $confirmLevel = 'stored'): array
    {
        $provider = $written->provider;
        $before = $written->before->of($provider);
        $after = $observed->of($provider);
        if ($provider === Provider::Pms) {
            $sent = array_keys($written->sent->all());
            $before = $before->only($sent);
            $after = $after->only($sent);
        }
        $undo = UndoAction::inverse($provider, $before, $after);
        $row = fn (string $field, int|float|string|null $old, int|float|string|null $new): ChangeRecord => new ChangeRecord(
            $this->propertyId,
            $provider,
            $written->roomCode,
            $written->night,
            $field,
            $old,
            $new,
            $undo,
            $this->actor,
            $at,
            self::amount($field, $old, $base),
            self::amount($field, $new, $base),
            $written->reasonCode,
            $written->reasonText,
            $written->orderId,
            $this->runId,
            $written->groupKey,
            null,
            $confirmLevel,
        );
        $rows = [];
        if ($provider === Provider::Pricing && $before->isEmpty() !== $after->isEmpty()) {
            $rows[] = $row(ChangeField::RULE, $before->isEmpty() ? ChangeRecord::ABSENT : ChangeRecord::PRESENT, $after->isEmpty() ? ChangeRecord::ABSENT : ChangeRecord::PRESENT);
        }
        foreach ($before->differs($after) as $field) {
            $rows[] = $row($field, $before->get($field), $after->get($field));
        }

        return $rows;
    }

    /**
     * The row of a listing value confirmed by the re-read of the listing.
     */
    public function listing(string $room, ListingField $field, float $old, float $new, DateTimeImmutable $at, string $reasonCode = '', string $reasonText = '', ?string $orderId = null): ChangeRecord
    {
        $name = self::LISTING_FIELDS[$field->value];

        return new ChangeRecord(
            $this->propertyId,
            Provider::Listing,
            $room,
            null,
            $name,
            $old,
            $new,
            UndoAction::inverse(Provider::Listing, new NightFields([$name => $old]), new NightFields([$name => $new])),
            $this->actor,
            $at,
            $old,
            $new,
            $reasonCode,
            $reasonText,
            $orderId,
            $this->runId,
        );
    }

    private static function amount(string $field, int|float|string|null $value, float $base): ?float
    {
        return ChangeField::isPercent($field) && (is_int($value) || is_float($value)) ? Num::percentToAmount((float) $value, $base) : null;
    }
}
