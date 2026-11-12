<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Settings of the plan validator, the payload and its slices (section "plan" of the rule set).
 *
 * The safety rules themselves (floors, the weekly limit of a listing, tonight never rises) live in
 * the rule set; these are the knobs of how a plan is checked and cut for the pricing system.
 *
 * Reference: validar_plano.py (--horas, Regra I, padrão de qualidade, sem_desconto, the stay range,
 * the 15% rise warning, montar_payload) and fatiar_payload.py (--max-datas).
 */
final class PlanConfig
{
    public const ROOT = 'plan';

    /**
     * @param list<string> $occupancyReasonCodes reason codes whose decision depends on occupancy
     * @param list<string> $riseExemptReasonCodes reason codes that may rise without the warning
     * @param list<string> $noDiscountRooms      room codes that never go under their current price
     */
    public function __construct(
        public readonly float $calendarMaxAgeHours = 6.0,
        public readonly array $occupancyReasonCodes = ['SOLD_OUT_PROTECTION', 'RELATION_DISCOUNT_BAND', 'RELATION_OCCUPANCY_SWITCH'],
        public readonly float $riseWarningPercent = 15.0,
        public readonly array $riseExemptReasonCodes = ['SOLD_OUT_PROTECTION'],
        public readonly int $maxMinStay = 30,
        public readonly int $marketEvidenceMinLength = 20,
        public readonly array $noDiscountRooms = [],
        public readonly LastMinuteRule $lastMinute = new LastMinuteRule(),
        public readonly int $reasonMaxLength = 250,
        public readonly int $sliceMaxDates = 70,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "plan" section; missing keys take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $v = $d['validator'];
        $p = $d['payload'];
        $config = new self(
            $reader->float($v['calendar_max_age_hours'], 'plan.validator.calendar_max_age_hours'),
            self::texts($reader, $v['occupancy_reason_codes'], 'plan.validator.occupancy_reason_codes'),
            $reader->float($v['rise_warning_percent'], 'plan.validator.rise_warning_percent'),
            self::texts($reader, $v['rise_exempt_reason_codes'], 'plan.validator.rise_exempt_reason_codes'),
            $reader->int($v['max_min_stay'], 'plan.validator.max_min_stay'),
            $reader->int($v['market_evidence_min_length'], 'plan.validator.market_evidence_min_length'),
            self::texts($reader, $v['no_discount_rooms'], 'plan.validator.no_discount_rooms'),
            LastMinuteRule::read($reader, $v['last_minute'], 'plan.validator.last_minute'),
            $reader->int($p['reason_max_length'], 'plan.payload.reason_max_length'),
            $reader->int($p['slice_max_dates'], 'plan.payload.slice_max_dates'),
        );
        $reader->finish(self::ROOT);

        return $config;
    }

    /**
     * The definitions of the "plan" section, in the format of the rule set schema.
     *
     * @return list<Field>
     */
    public static function fields(): array
    {
        $code = Identifier::CODE_PATTERN;

        return [
            new Field('plan', FieldType::Group, 'Plan checks and payload', 'How a plan is validated before it can be written and how it is cut for the pricing system.'),
            new Field('plan.validator', FieldType::Group, 'Validator', 'Checks of every plan; the safety limits themselves are in the floors and safety sections.'),
            new Field('plan.validator.calendar_max_age_hours', FieldType::Number, 'Fresh calendar (hours)', 'A decision that depends on occupancy needs a pricing calendar recalculated at most this many hours ago; an older one is refused unless accepted explicitly for one validation.', 6.0, min: 0.5, max: 168),
            new Field('plan.validator.occupancy_reason_codes', FieldType::ListOf, 'Decisions that depend on occupancy', 'Reason codes of the items whose decision reads units sold or free; they need the fresh calendar. A floor under the listing minimum always does.', ['SOLD_OUT_PROTECTION', 'RELATION_DISCOUNT_BAND', 'RELATION_OCCUPANCY_SWITCH'], max: 50, unique: true),
            new Field('plan.validator.occupancy_reason_codes.*', FieldType::Text, 'Reason code', min: 1, max: 60, pattern: '/^[A-Za-z][A-Za-z0-9_]*$/'),
            new Field('plan.validator.rise_warning_percent', FieldType::Number, 'Rise warning (%)', 'A floor this much above the current price gets a warning: the pricing system moves one step a day.', 15.0, min: 1, max: 100, unit: Field::UNIT_PERCENT),
            new Field('plan.validator.rise_exempt_reason_codes', FieldType::ListOf, 'Rises without warning', 'Reason codes allowed to rise further without the warning (the protection of a sold-out night).', ['SOLD_OUT_PROTECTION'], max: 50, unique: true),
            new Field('plan.validator.rise_exempt_reason_codes.*', FieldType::Text, 'Reason code', min: 1, max: 60, pattern: '/^[A-Za-z][A-Za-z0-9_]*$/'),
            new Field('plan.validator.max_min_stay', FieldType::Integer, 'Longest minimum stay', 'A minimum stay above this is refused as a mistake.', 30, min: 1, max: 30, unit: Field::UNIT_NIGHTS),
            new Field('plan.validator.market_evidence_min_length', FieldType::Integer, 'Market evidence length', 'Going under the quality floor needs market evidence in the item: a text of at least this many characters with a number in it.', 20, min: 1, max: 500),
            new Field('plan.validator.no_discount_rooms', FieldType::ListOf, 'Rooms without discounts', 'Rooms that never go under their current price, by floor or by ceiling.', [], max: 200, unique: true),
            new Field('plan.validator.no_discount_rooms.*', FieldType::Text, 'Room', min: 1, max: 40, pattern: $code),
            ...LastMinuteRule::fields('plan.validator.last_minute', $code),
            new Field('plan.payload', FieldType::Group, 'Payload', 'How the validated plan is written to the pricing system.'),
            new Field('plan.payload.reason_max_length', FieldType::Integer, 'Reason length', 'The reason written with a date rule is cut to this many characters (the pricing system keeps 255).', 250, min: 1, max: 255),
            new Field('plan.payload.slice_max_dates', FieldType::Integer, 'Dates per call', 'At most this many dates of one room in one call; tonight and tomorrow always go in calls of their own.', 70, min: 1, max: 366),
        ];
    }

    /**
     * @return list<string>
     */
    private static function texts(ConfigReader $reader, mixed $value, string $path): array
    {
        $texts = [];
        foreach ($reader->items($value, $path) as $i => $text) {
            $texts[] = $reader->string($text, "$path.$i");
        }

        return $texts;
    }

    /**
     * True when the reason code is one whose decision depends on occupancy.
     */
    public function dependsOnOccupancy(string $reasonCode): bool
    {
        return in_array($reasonCode, $this->occupancyReasonCodes, true);
    }
}
