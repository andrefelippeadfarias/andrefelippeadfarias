<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;

/**
 * The definitions of the "stay.write" section (spec 06, 3.4 and 6): whether the stay rules write
 * for real, how far, how many PMS changes a run may carry, how many times a value the pricing
 * push put back may be written again, and whether the pricing side raises its own stays.
 * Neutral: off (the audit only alerts, as in M3).
 */
final class StayWriteFields
{
    public const ROOT = 'stay.write';

    private function __construct()
    {
    }

    /**
     * @return list<Field>
     */
    public static function all(): array
    {
        return [
            new Field('stay.write', FieldType::Group, 'Write the stay rules', 'Fix minimum stays and arrival/departure days in the PMS and the pricing system; off, the audit only raises alerts.'),
            new Field('stay.write.enabled', FieldType::Boolean, 'On', 'Write the stay and arrival/departure changes the rules ask for (always through the approval of the mode).', false),
            new Field('stay.write.horizon_days', FieldType::Integer, 'How far ahead', 'Nights from today the stay rules may change.', 60, min: 7, max: 366, unit: Field::UNIT_DAYS),
            new Field('stay.write.max_changes_per_run', FieldType::Integer, 'Most PMS changes in one run', 'Above this, every PMS change of the run is refused and an alert is raised (a rule set by mistake would rewrite the calendar).', 300, min: 1, max: 5000),
            new Field('stay.write.revert_limit', FieldType::Integer, 'Stop after the push undoes it', 'How many times a PMS value put back by the pricing system\'s push is written again before it is refused with an alert.', 2, min: 1, max: 5),
            new Field('stay.write.raise_own_stays', FieldType::Boolean, 'Raise stays in the pricing system too', 'Off: the pricing side only lowers or opens stays (orphan nights) and reports the rest; on: it also raises closed days, holidays and packages to their own stay.', false),
        ];
    }
}
