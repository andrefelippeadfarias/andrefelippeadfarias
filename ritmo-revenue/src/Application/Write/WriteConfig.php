<?php

declare(strict_types=1);

namespace Ritmo\Application\Write;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Settings of the write path (section "write"): how fresh a plan and its reads must be for the
 * gate to issue a token, how long a token lives, how the steps are cut, when a refused body is
 * quarantined, and whether listing changes may be written at all. Defaults are neutral and
 * conservative; the parity values are in tests/fixtures/presets/parity-write.json.
 */
final class WriteConfig
{
    public const ROOT = 'write';

    public function __construct(
        public readonly int $maxReadAgeMinutes = 30,
        public readonly int $maxValidationAgeMinutes = 10,
        public readonly int $tokenTtlMinutes = 15,
        public readonly int $horizonDays = 365,
        public readonly int $horizonEdgeDays = 2,
        public readonly int $maxIdenticalRefusals = 2,
        public readonly bool $listingChangesEnabled = false,
        public readonly int $pmsMaxRangesPerPost = 100,
        public readonly int $callTimeoutSeconds = 120,
        public readonly int $maxDatesPerCall = 70,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "write" section; missing keys take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self(
            $reader->int($d['max_read_age_minutes'], 'write.max_read_age_minutes'),
            $reader->int($d['max_validation_age_minutes'], 'write.max_validation_age_minutes'),
            $reader->int($d['token_ttl_minutes'], 'write.token_ttl_minutes'),
            $reader->int($d['horizon_days'], 'write.horizon_days'),
            $reader->int($d['horizon_edge_days'], 'write.horizon_edge_days'),
            $reader->int($d['max_identical_refusals'], 'write.max_identical_refusals'),
            $reader->bool($d['listing_changes_enabled'], 'write.listing_changes_enabled'),
            $reader->int($d['pms_max_ranges_per_post'], 'write.pms_max_ranges_per_post'),
            $reader->int($d['call_timeout_seconds'], 'write.call_timeout_seconds'),
            $reader->int($d['max_dates_per_call'], 'write.max_dates_per_call'),
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
            new Field('write', FieldType::Group, 'Writing', 'When the plugin may send what a validated plan changes, and how.'),
            new Field('write.max_read_age_minutes', FieldType::Integer, 'Reads at most this old', 'A plan is written only while the calendars it was decided on were read at most this long ago.', 30, min: 5, max: 180),
            new Field('write.max_validation_age_minutes', FieldType::Integer, 'Validation at most this old', 'A plan validated longer ago than this is validated again before anything is written.', 10, min: 1, max: 60),
            new Field('write.token_ttl_minutes', FieldType::Integer, 'Write permission lasts', 'Minutes a write permission lives; it never outlives the property\'s midnight and ends with the run.', 15, min: 1, max: 60),
            new Field('write.horizon_days', FieldType::Integer, 'Pricing horizon', 'Nights ahead the pricing system prices.', 365, min: 30, max: 730, unit: Field::UNIT_DAYS),
            new Field('write.horizon_edge_days', FieldType::Integer, 'Horizon edge in calls of their own', 'Dates this close to the end of the horizon go in calls of their own: one refused far date must not refuse the rest.', 2, min: 0, max: 30, unit: Field::UNIT_DAYS),
            new Field('write.max_identical_refusals', FieldType::Integer, 'Refusals before quarantine', 'A body refused this many runs in a row is not sent again until someone looks at it.', 2, min: 1, max: 5),
            new Field('write.listing_changes_enabled', FieldType::Boolean, 'Listing changes', 'Allow changes of a listing\'s minimum, base and maximum; even then only with a person\'s approval, never in autonomous mode.', false),
            new Field('write.pms_max_ranges_per_post', FieldType::Integer, 'PMS ranges per call', 'Ranges of nights in one PMS calendar call; each call is read back before the next.', 100, min: 1, max: 100),
            new Field('write.call_timeout_seconds', FieldType::Integer, 'Time of one call', 'Seconds a write call may take; a delete and its rewrite start only with time for both.', 120, min: 10, max: 300),
            new Field('write.max_dates_per_call', FieldType::Integer, 'Dates per pricing call', 'Date rules of one listing in one call.', 70, min: 1, max: 70),
        ];
    }
}
