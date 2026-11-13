<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\RunLines;

/**
 * The lines a run report can carry, each with the parameters it takes (spec 06, 2.8). The text
 * is written at display time, in the reader's language.
 */
enum RunLineCode: string
{
    case LightIdle = 'light_idle';
    case SyncNow = 'sync_now';
    case SecurityFindings = 'security_findings';
    case Overbooked = 'overbooked';
    case WaveHold = 'wave_hold';
    case ShadowWouldWrite = 'shadow_would_write';
    case WaitingApproval = 'waiting_approval';
    case GateRefused = 'gate_refused';
    case Written = 'written';
    case WriteStopped = 'write_stopped';
    case Held = 'held';
    case ValidatorRefused = 'validator_refused';
    case DeliveryOverdue = 'delivery_overdue';
    case MarketStale = 'market_stale';
    case PmsSkipped = 'pms_skipped';
    case StayMergeConflict = 'stay_merge_conflict';

    /**
     * The parameters of the line: name => 'int' (a count or number of days) or 'code' (a reason
     * code, lowercase letters, digits and underscores).
     *
     * @return array<string, 'int'|'code'>
     */
    public function params(): array
    {
        return match ($this) {
            self::LightIdle, self::SyncNow, self::WaveHold => [],
            self::SecurityFindings, self::Overbooked, self::Held, self::DeliveryOverdue, self::StayMergeConflict => ['count' => 'int'],
            self::ShadowWouldWrite => ['items' => 'int', 'pms' => 'int'],
            self::WaitingApproval => ['entries' => 'int'],
            self::GateRefused, self::WriteStopped => ['reason' => 'code'],
            self::Written => ['calls' => 'int', 'confirmed' => 'int', 'pms_confirmed' => 'int'],
            self::ValidatorRefused => ['count' => 'int', 'pms' => 'int'],
            self::MarketStale => ['days' => 'int'],
            self::PmsSkipped => ['count' => 'int', 'reason' => 'code'],
        };
    }
}
