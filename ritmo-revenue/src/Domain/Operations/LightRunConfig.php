<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * What a light run reads and reacts to (section "light_run"; the operations spec nests it under
 * "operations"). Light runs happen only at the light times of the schedule, which are off by
 * default.
 */
final class LightRunConfig
{
    public const ROOT = 'light_run';

    public function __construct(
        public readonly int $movementOverlapMinutes = 15,
        public readonly int $initialLookbackHours = 24,
        public readonly int $protectFlipDays = 1,
        public readonly int $orphanWindowDays = 7,
        public readonly bool $readRulesWithoutMovement = false,
        public readonly bool $readListingStatus = true,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "light_run" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self(
            $reader->int($d['movement_overlap_minutes'], 'light_run.movement_overlap_minutes'),
            $reader->int($d['initial_lookback_hours'], 'light_run.initial_lookback_hours'),
            $reader->int($d['protect_flip_days'], 'light_run.protect_flip_days'),
            $reader->int($d['orphan_window_days'], 'light_run.orphan_window_days'),
            $reader->bool($d['read_rules_without_movement'], 'light_run.read_rules_without_movement'),
            $reader->bool($d['read_listing_status'], 'light_run.read_listing_status'),
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
            new Field('light_run', FieldType::Group, 'Light runs', 'A light run reads the bookings that changed since the previous run and acts only on the nights they touch.'),
            new Field('light_run.movement_overlap_minutes', FieldType::Integer, 'Read margin', 'The bookings read start this many minutes before the previous read, so nothing falls between two reads. A booking already processed is never counted twice.', 15, min: 0, max: 120, unit: 'minutes'),
            new Field('light_run.initial_lookback_hours', FieldType::Integer, 'First read covers', 'The first read of a property covers this many hours; it only records what exists (no plan) and alerts on what changed in that time.', 24, min: 1, max: 168, unit: 'hours'),
            new Field('light_run.protect_flip_days', FieldType::Integer, 'React to sold out or reopened nights up to', 'A night that sold out or reopened from today to this many days ahead is protected or priced at once, even without a booking (a block, a room move).', 1, min: 0, max: 3, unit: Field::UNIT_DAYS),
            new Field('light_run.orphan_window_days', FieldType::Integer, 'Redo orphan stays up to', 'A night that sold out or reopened from today to this many days ahead re-plans the orphan-night stays of its room in both systems at once.', 7, min: 0, max: 14, unit: Field::UNIT_DAYS),
            new Field('light_run.read_rules_without_movement', FieldType::Boolean, 'Read the date rules even when nothing moved', 'More calls, and manual changes in the pricing system found sooner.', false),
            new Field('light_run.read_listing_status', FieldType::Boolean, 'Read the listing status every light run', 'One read of the pricing system\'s listing directory: the last push and the push switch, needed to confirm deliveries.', true),
        ];
    }
}
