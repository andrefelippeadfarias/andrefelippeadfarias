<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * The condition alerts a full run checks at its end (market spec 1.7, reports spec 2.4, M3
 * pendency D5): old market prices, nights a sales ladder cannot price, steps not confirmed at the
 * channel and the 12-month review due. Each is one open alert per property, resolved by the run
 * that no longer finds the condition.
 */
final class RunAlerts
{
    /** The codes a full run checks (and resolves when the condition is gone). */
    public const CODES = [AlertCode::MarketStale, AlertCode::MarketGap, AlertCode::DeliveryOverdue, AlertCode::ReviewDue, AlertCode::PmsChangesCapped];

    private function __construct()
    {
    }

    public static function marketStale(string $propertyId, ?int $ageDays, ?DateTimeImmutable $newest): AlertDraft
    {
        return new AlertDraft(
            AlertCode::MarketStale,
            $propertyId,
            [],
            $ageDays === null ? 'There are no market prices for the coming nights.' : sprintf('The newest market price is %d days old.', $ageDays),
            ['days' => $ageDays, 'from' => AlertDraft::instant($newest)],
        );
    }

    /**
     * @param list<DateTimeImmutable> $nights the nights without a value on the ruler
     */
    public static function marketGap(string $propertyId, array $nights): AlertDraft
    {
        $keys = array_map(static fn (DateTimeImmutable $n): string => Days::key($n), $nights);

        return new AlertDraft(
            AlertCode::MarketGap,
            $propertyId,
            [],
            sprintf('%d of the next nights have no market price for an active sales ladder.', count($keys)),
            ['count' => count($keys), 'from' => $keys[0] ?? null, 'to' => $keys === [] ? null : $keys[count($keys) - 1]],
        );
    }

    public static function deliveryOverdue(string $propertyId, int $steps): AlertDraft
    {
        return new AlertDraft(
            AlertCode::DeliveryOverdue,
            $propertyId,
            [],
            sprintf('%d written step(s) are not confirmed at the channel in time.', $steps),
            ['count' => $steps],
        );
    }

    public static function reviewDue(string $propertyId, ?DateTimeImmutable $last): AlertDraft
    {
        return new AlertDraft(
            AlertCode::ReviewDue,
            $propertyId,
            [],
            'The 12-month review is due.',
            ['from' => AlertDraft::instant($last)],
        );
    }

    public static function pmsChangesCapped(string $propertyId, int $limit): AlertDraft
    {
        return new AlertDraft(
            AlertCode::PmsChangesCapped,
            $propertyId,
            [],
            sprintf('The run planned more than %d PMS changes; none was written.', $limit),
            ['count' => $limit],
        );
    }
}
