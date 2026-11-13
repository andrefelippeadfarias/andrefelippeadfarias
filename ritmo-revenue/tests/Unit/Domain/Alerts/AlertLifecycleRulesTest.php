<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\AlertLifecycleRules;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\AlertStatus;
use Ritmo\Domain\Alerts\Delivery;
use Ritmo\Domain\Alerts\NotificationPolicy;
use Ritmo\Domain\Alerts\RaiseAction;

/**
 * Dedupe, reopening, automatic resolution (O26 to O28) and who is told (O29).
 */
final class AlertLifecycleRulesTest extends TestCase
{
    public static function record(AlertCode $code, AlertStatus $status, string $key = 'k', ?string $resolvedAt = null, int $occurrences = 1, ?string $severity = null): AlertRecord
    {
        $at = new DateTimeImmutable('2026-11-12T10:00:00Z');

        return new AlertRecord(1, $code->value, $severity === null ? $code->severity() : \Ritmo\Domain\Alerts\AlertSeverity::from($severity), $status, 'inn', '', null, 'm', [], $key, null, $at, $at, $resolvedAt === null ? null : new DateTimeImmutable($resolvedAt), occurrences: $occurrences);
    }

    private static function draft(AlertCode $code, ?string $evidence = null): AlertDraft
    {
        return new AlertDraft($code, 'inn', ['x'], 'm', evidenceAt: $evidence === null ? null : new DateTimeImmutable($evidence));
    }

    public function testAnOpenOrAcknowledgedAlertIsRefreshed(): void
    {
        self::assertSame(RaiseAction::Insert, AlertLifecycleRules::decide(self::draft(AlertCode::Overbooked), null));
        self::assertSame(RaiseAction::Refresh, AlertLifecycleRules::decide(self::draft(AlertCode::Overbooked), self::record(AlertCode::Overbooked, AlertStatus::Open)));
        self::assertSame(RaiseAction::Refresh, AlertLifecycleRules::decide(self::draft(AlertCode::SecurityZeroValue), self::record(AlertCode::SecurityZeroValue, AlertStatus::Acknowledged)));
    }

    public function testAResolvedConditionComesBackAsANewRow(): void
    {
        self::assertSame(RaiseAction::Insert, AlertLifecycleRules::decide(self::draft(AlertCode::Overbooked), self::record(AlertCode::Overbooked, AlertStatus::Resolved, resolvedAt: '2026-11-12T11:00:00Z')));
    }

    public function testAResolvedEventComesBackOnlyWithNewerEvidence(): void
    {
        $resolved = self::record(AlertCode::SecurityUnknownOrigin, AlertStatus::Resolved, resolvedAt: '2026-11-12T11:00:00Z');

        self::assertSame(RaiseAction::Ignore, AlertLifecycleRules::decide(self::draft(AlertCode::SecurityUnknownOrigin, '2026-11-12T10:30:00Z'), $resolved));
        self::assertSame(RaiseAction::Ignore, AlertLifecycleRules::decide(self::draft(AlertCode::SecurityUnknownOrigin), $resolved));
        self::assertSame(RaiseAction::Insert, AlertLifecycleRules::decide(self::draft(AlertCode::SecurityUnknownOrigin, '2026-11-12T11:30:00Z'), $resolved));
    }

    public function testOnlyConditionsNotRaisedAgainAndInScopeAreStale(): void
    {
        $active = [
            self::record(AlertCode::Overbooked, AlertStatus::Open, 'gone'),
            self::record(AlertCode::Overbooked, AlertStatus::Acknowledged, 'still'),
            self::record(AlertCode::WriteFailed, AlertStatus::Open, 'event'),
        ];

        $stale = AlertLifecycleRules::stale($active, ['still' => true]);
        self::assertSame(['gone'], array_map(static fn (AlertRecord $a): string => $a->dedupeKey, $stale));
        self::assertSame([], AlertLifecycleRules::stale($active, ['still' => true], static fn (AlertRecord $a): bool => false));
    }

    public function testCriticalAlertsAreMailedOncePerEpisodeWithinTheCap(): void
    {
        $policy = new NotificationPolicy(6, 3);
        $critical = self::record(AlertCode::WriteFailed, AlertStatus::Open);

        self::assertSame(Delivery::Immediate, $policy->delivery($critical, RaiseAction::Insert, 5));
        self::assertSame(Delivery::Digest, $policy->delivery($critical, RaiseAction::Insert, 6), 'over the cap: the digest');
        self::assertSame(Delivery::None, $policy->delivery($critical, RaiseAction::Refresh, 0), 'a refresh sends nothing');
        self::assertSame(Delivery::Digest, $policy->delivery(self::record(AlertCode::RunLate, AlertStatus::Open), RaiseAction::Insert, 0));
        self::assertSame(Delivery::None, $policy->delivery(self::record(AlertCode::ApprovalExpired, AlertStatus::Open), RaiseAction::Insert, 0));
        self::assertSame(Delivery::Immediate, $policy->delivery(self::record(AlertCode::ApprovalPending, AlertStatus::Open), RaiseAction::Insert, 0));
    }

    public function testAConnectorStillDownIsMailedOnceMoreAfterTheConfiguredRuns(): void
    {
        $policy = new NotificationPolicy(6, 3);

        self::assertSame(Delivery::None, $policy->delivery(self::record(AlertCode::ConnectorDown, AlertStatus::Open, occurrences: 2), RaiseAction::Refresh, 0));
        self::assertSame(Delivery::Immediate, $policy->delivery(self::record(AlertCode::ConnectorDown, AlertStatus::Open, occurrences: 3), RaiseAction::Refresh, 0));
        self::assertSame(Delivery::None, $policy->delivery(self::record(AlertCode::ConnectorDown, AlertStatus::Open, occurrences: 4), RaiseAction::Refresh, 0));
        self::assertSame(Delivery::Digest, $policy->delivery(self::record(AlertCode::ConnectorDown, AlertStatus::Open, occurrences: 3, severity: 'warning'), RaiseAction::Refresh, 0), 'the pricing system down is a warning');
    }
}
