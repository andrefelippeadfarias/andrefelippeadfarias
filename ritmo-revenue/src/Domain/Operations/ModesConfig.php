<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * When a property may leave the shadow mode (section "modes"). Every property starts in shadow;
 * approval needs a few shadow runs first; the autonomous mode is off for the whole site until it
 * is switched on here, and then a property reaches it only from approval, after enough days and
 * written plans. A property in autonomous mode goes back to approval on its own after a number of
 * runs in a row whose writes failed. Nothing raises a mode on its own.
 */
final class ModesConfig
{
    public const ROOT = 'modes';

    public function __construct(
        public readonly bool $autonomousEnabled = false,
        public readonly int $minShadowRuns = 3,
        public readonly int $minApprovalDays = 7,
        public readonly int $minWrittenPlans = 5,
        public readonly int $demoteAfterFailedWrites = 2,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "modes" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self(
            $reader->bool($d['autonomous_enabled'], 'modes.autonomous_enabled'),
            $reader->int($d['min_shadow_runs'], 'modes.min_shadow_runs'),
            $reader->int($d['min_approval_days'], 'modes.min_approval_days'),
            $reader->int($d['min_written_plans'], 'modes.min_written_plans'),
            $reader->int($d['demote_after_failed_writes'], 'modes.demote_after_failed_writes'),
        );
        $reader->finish(self::ROOT);

        return $config;
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('modes', FieldType::Group, 'Operating modes', 'Every property starts in shadow (computes, writes nothing). Approval writes only what a person approved; autonomous writes on its own inside the safety limits.'),
            new Field('modes.autonomous_enabled', FieldType::Boolean, 'Allow the autonomous mode', 'Off: no property can be switched to autonomous. On: a person who manages the connections can switch a property from approval to autonomous, typing a confirmation.', false),
            new Field('modes.min_shadow_runs', FieldType::Integer, 'Shadow runs before approval', 'Completed shadow runs a property needs before it can be switched to approval.', 3, min: 0, max: 30),
            new Field('modes.min_approval_days', FieldType::Integer, 'Days in approval before autonomous', 'Days a property must spend in approval before it can be switched to autonomous.', 7, min: 0, max: 90, unit: Field::UNIT_DAYS),
            new Field('modes.min_written_plans', FieldType::Integer, 'Approved plans written before autonomous', 'Plans approved and written in approval mode before the property can be switched to autonomous.', 5, min: 0, max: 100),
            new Field('modes.demote_after_failed_writes', FieldType::Integer, 'Back to approval after failed writes', 'Runs in a row whose writes failed after which an autonomous property goes back to approval.', 2, min: 1, max: 10),
        ];
    }
}
