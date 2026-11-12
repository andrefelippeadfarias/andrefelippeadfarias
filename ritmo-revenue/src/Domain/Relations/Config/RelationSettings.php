<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Config;

use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\BlockedNights;
use Ritmo\Domain\Relations\RecheckMode;

/**
 * The general settings of the relations (key "relations" of the rule set, without the items).
 * The defaults are the neutral values of presets/default.json.
 */
final class RelationSettings
{
    public const MAX_AMOUNT = 1000.0;

    /**
     * @param float $triggerAmount    a relation acts only when a price is off by more than this amount
     * @param float $auditBasePercent share of the listing base in the audit tolerance (one step of the percent grid)
     * @param float $auditAmount      fixed part of the audit tolerance
     * @param int   $scanHorizonDays  nights a relation of scan "horizon" checks in a full run
     * @param int   $auditHorizonDays nights the daily audit covers
     * @param float $recheckEpsilon   differences below this are ignored by the currency re-check
     */
    public function __construct(
        public readonly float $triggerAmount = 1.0,
        public readonly float $auditBasePercent = 1.0,
        public readonly float $auditAmount = 1.0,
        public readonly int $scanHorizonDays = 60,
        public readonly int $auditHorizonDays = 366,
        public readonly BlockedNights $blockedNights = BlockedNights::Exclude,
        public readonly RecheckMode $recheckMode = RecheckMode::Fix,
        public readonly float $recheckEpsilon = 0.005,
    ) {
    }

    public static function read(Reader $reader): self
    {
        $tolerance = $reader->group('tolerance');
        $tolerance->rejectUnknown(['trigger_amount', 'audit_base_percent', 'audit_amount']);
        $recheck = $reader->group('rounding_recheck');
        $recheck->rejectUnknown(['mode', 'epsilon_amount']);

        return new self(
            $tolerance->number('trigger_amount', 1.0, 0.0, self::MAX_AMOUNT),
            $tolerance->number('audit_base_percent', 1.0, 0.0, 10.0),
            $tolerance->number('audit_amount', 1.0, 0.0, self::MAX_AMOUNT),
            $reader->integer('scan_horizon_days', 60, 0, 365),
            $reader->integer('audit_horizon_days', 366, 0, 730),
            $reader->choice('blocked_nights', BlockedNights::class, BlockedNights::Exclude),
            $recheck->choice('mode', RecheckMode::class, RecheckMode::Fix),
            $recheck->number('epsilon_amount', 0.005, 0.0, 1.0),
        );
    }

    /**
     * The audit tolerance of a room: one step of its percent grid plus a fixed amount
     * (1% of the base + 1 with the defaults).
     */
    public function auditTolerance(RoomType $room): float
    {
        return $this->auditBasePercent / 100 * $room->base + $this->auditAmount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tolerance' => [
                'trigger_amount' => $this->triggerAmount,
                'audit_base_percent' => $this->auditBasePercent,
                'audit_amount' => $this->auditAmount,
            ],
            'scan_horizon_days' => $this->scanHorizonDays,
            'audit_horizon_days' => $this->auditHorizonDays,
            'blocked_nights' => $this->blockedNights->value,
            'rounding_recheck' => ['mode' => $this->recheckMode->value, 'epsilon_amount' => $this->recheckEpsilon],
        ];
    }
}
