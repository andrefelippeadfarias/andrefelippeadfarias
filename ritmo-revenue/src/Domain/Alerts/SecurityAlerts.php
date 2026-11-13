<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Support\Days;

/**
 * The alerts of the security watch and of the bookings below expected. Bookings appear by id,
 * room, dates, status, channel, origin and amounts only.
 */
final class SecurityAlerts
{
    private const MESSAGES = [
        'unknown_origin' => 'A booking was created or changed by an origin outside the known list: confirm it with the front desk.',
        'zero_value' => 'A confirmed booking has no value.',
        'zero_value_modified' => 'An older booking was changed or reactivated with no value.',
    ];

    private const CODES = [
        'unknown_origin' => AlertCode::SecurityUnknownOrigin,
        'zero_value' => AlertCode::SecurityZeroValue,
        'zero_value_modified' => AlertCode::SecurityZeroValueModified,
    ];

    /**
     * @param list<SecurityFinding> $findings
     *
     * @return list<AlertDraft>
     */
    public static function fromFindings(string $propertyId, array $findings): array
    {
        $drafts = [];
        foreach ($findings as $finding) {
            if ($finding->wave !== null) {
                $wave = $finding->wave;
                $drafts[] = new AlertDraft(
                    AlertCode::SecurityCancellationBurst,
                    $propertyId,
                    [$wave->channel, (string) $wave->firstAt->getTimestamp()],
                    sprintf('%d cancellations of one channel in a short time: confirm with the front desk that the guests cancelled. Price cuts wait until a person confirms.', count($wave->bookingRefs)),
                    [
                        'channel' => $wave->channel,
                        'booking_ids' => $wave->bookingRefs,
                        'first_at' => AlertDraft::instant($wave->firstAt),
                        'last_at' => AlertDraft::instant($wave->lastAt),
                    ],
                    evidenceAt: $wave->lastAt,
                );
            } elseif ($finding->booking !== null) {
                $booking = $finding->booking;
                $drafts[] = new AlertDraft(
                    self::CODES[$finding->kind->value],
                    $propertyId,
                    [$booking->pmsRef],
                    self::MESSAGES[$finding->kind->value],
                    self::bookingFacts($booking),
                    $booking->roomCode ?? '',
                    evidenceAt: $booking->modifiedAt ?? $booking->createdAt,
                );
            }
        }

        return $drafts;
    }

    /**
     * @param list<BelowExpectedFinding> $findings
     *
     * @return list<AlertDraft>
     */
    public static function belowExpected(string $propertyId, array $findings): array
    {
        return array_map(static fn (BelowExpectedFinding $f): AlertDraft => new AlertDraft(
            AlertCode::BookingBelowExpected,
            $propertyId,
            [$f->booking->pmsRef],
            sprintf('A booking is worth %.0f%% of the expected value. Check it in the PMS; the plugin never changes bookings.', $f->ratio * 100),
            self::bookingFacts($f->booking) + ['nights' => $f->nights, 'expected' => $f->expected, 'ratio' => $f->ratio],
            $f->booking->roomCode ?? '',
            evidenceAt: $f->booking->modifiedAt ?? $f->booking->createdAt,
            severity: $f->severity,
        ), $findings);
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function bookingFacts(BookingRecord $booking): array
    {
        return [
            'booking_id' => $booking->pmsRef,
            'room' => $booking->roomCode,
            'arrival' => Days::key($booking->arrival),
            'departure' => Days::key($booking->departure),
            'status' => $booking->status->value,
            'channel' => $booking->channel,
            'origin' => $booking->origin,
            'total' => $booking->total,
            'created_at' => AlertDraft::instant($booking->createdAt),
        ];
    }
}
