<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use DateTimeImmutable;
use Ritmo\Application\Verify\NightConfirmation;
use Ritmo\Application\Verify\StoredStatus;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * The nights a write run stored, as the confirmation and record area reads them: every journal
 * row the executor read back as stored becomes one confirmed night per night it wrote, with what
 * it sent, what the night held before and what the read-back found. Rows not stored (refused,
 * unknown, mismatched, abandoned) record nothing: the change log and the step ledger take only
 * what a read-back confirmed.
 */
final class JournalNights
{
    /** Restriction of the PMS body => the plugin's restriction. */
    private const RESTRICTIONS = [
        'none' => Restriction::None,
        'blackout' => Restriction::Blackout,
        'noCheckIn' => Restriction::NoCheckin,
        'noCheckOut' => Restriction::NoCheckout,
        'noCheckInOrCheckOut' => Restriction::NoCheckinCheckout,
    ];

    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $rows the journal rows of a run (WriteOpRepository::ofRun)
     *
     * @return list<NightConfirmation>
     */
    public static function confirmed(array $rows, DateTimeImmutable $writtenAt): array
    {
        $out = [];
        foreach ($rows as $row) {
            $operation = WriteOperation::tryFrom((string) ($row['operation'] ?? ''));
            if ($row['status'] !== OpStatus::Stored->value || !in_array($operation, [WriteOperation::OverridesWrite, WriteOperation::OverridesDelete, WriteOperation::PmsCalendar], true)) {
                continue;
            }
            array_push($out, ...self::nights($row, $operation, $writtenAt));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<NightConfirmation>
     */
    private static function nights(array $row, WriteOperation $operation, DateTimeImmutable $writtenAt): array
    {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $pms = $operation === WriteOperation::PmsCalendar;
        $provider = $pms ? Provider::Pms : Provider::Pricing;
        $kind = match (true) {
            $operation === WriteOperation::OverridesDelete => WriteKind::Delete,
            ($row['group_key'] ?? '') !== '' => WriteKind::Rewrite,
            default => WriteKind::Write,
        };
        $out = [];
        foreach ((array) ($row['intended_state'] ?? []) as $target => $nights) {
            $room = self::roomOf($payload, (string) $target);
            foreach ((array) $nights as $night => $want) {
                if (!Days::isValid((string) $night)) {
                    continue;
                }
                $sent = $kind === WriteKind::Delete ? NightFields::none() : self::fields($want['state'] ?? null, $pms)->only(self::names((array) ($want['fields'] ?? []), $pms));
                if ($kind !== WriteKind::Delete && $sent->isEmpty()) {
                    continue;
                }
                $written = new WrittenNight(
                    $provider,
                    $room,
                    Days::date((string) $night),
                    $kind,
                    $sent,
                    self::fields($row['before_state'][$target][$night] ?? null, $pms),
                    $writtenAt,
                    (string) ($row['group_key'] ?? ''),
                    journalRef: (string) ($row['id'] ?? ''),
                );
                $out[] = new NightConfirmation($written, StoredStatus::Stored, self::fields($row['observed_state'][$target][$night] ?? null, $pms));
            }
        }

        return $out;
    }

    /**
     * The room code of one target of a journal row: a PMS block names each room under its PMS id
     * ('rooms'); rows written before that map existed carry one room ('room').
     *
     * @param array<string, mixed> $payload
     */
    public static function roomOf(array $payload, string $target): string
    {
        $rooms = is_array($payload['rooms'] ?? null) ? $payload['rooms'] : [];

        return (string) ($rooms[$target] ?? $payload['room'] ?? '');
    }

    /**
     * A night of the journal (a date rule of the pricing system or the PMS values) as fields.
     *
     * @param array<string, mixed>|null $state
     */
    public static function fields(?array $state, bool $pms): NightFields
    {
        if ($state === null) {
            return NightFields::none();
        }
        $fields = $pms ? [
            ChangeField::PMS_MIN_STAY => $state['minStay'] ?? null,
            ChangeField::PMS_MAX_STAY => $state['maxStay'] ?? null,
            ChangeField::PMS_RESTRICTION => isset($state['override']) ? (self::RESTRICTIONS[(string) $state['override']] ?? null)?->value : null,
            ChangeField::PMS_AVAILABLE => $state['numAvail'] ?? null,
        ] : [
            ChangeField::FLOOR_PCT => $state['min_price'] ?? null,
            ChangeField::CEILING_PCT => $state['max_price'] ?? null,
            ChangeField::MIN_STAY => $state['min_stay'] ?? null,
            ChangeField::CHECK_IN_MASK => $state['check_in'] ?? null,
            ChangeField::CHECK_OUT_MASK => $state['check_out'] ?? null,
        ];

        return new NightFields(array_filter($fields, static fn (mixed $v): bool => $v !== null));
    }

    /**
     * The journal's field names as the change log names them.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function names(array $names, bool $pms): array
    {
        $map = $pms
            ? ['minStay' => ChangeField::PMS_MIN_STAY, 'maxStay' => ChangeField::PMS_MAX_STAY, 'override' => ChangeField::PMS_RESTRICTION, 'numAvail' => ChangeField::PMS_AVAILABLE]
            : ['min_price' => ChangeField::FLOOR_PCT, 'max_price' => ChangeField::CEILING_PCT, 'min_stay' => ChangeField::MIN_STAY, 'check_in' => ChangeField::CHECK_IN_MASK, 'check_out' => ChangeField::CHECK_OUT_MASK];

        return array_values(array_filter(array_map(static fn (string $n): ?string => $map[$n] ?? null, $names)));
    }
}
