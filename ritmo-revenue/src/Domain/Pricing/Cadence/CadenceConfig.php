<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Settings of the step cadence: one step per night per day and direction, and its exceptions.
 *
 * Reference: pace.cadencia_permite, ordem_nova_sem_passo, plano_no_ledger and item_do_plano.
 */
final class CadenceConfig
{
    public const ROOT = 'cadence';

    public function __construct(
        public readonly int $minHoursBetweenSteps = 24,
        public readonly bool $requireChannelArrival = true,
        public readonly bool $oppositeDirectionImmediate = true,
        public readonly bool $releaseOnBooking = true,
        public readonly bool $newOrderImmediateOnce = true,
        public readonly bool $firstLadderStepImmediate = true,
        public readonly bool $relationViolationBypass = true,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "cadence" section; missing keys take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self(
            $reader->int($d['min_hours_between_steps'], 'cadence.min_hours_between_steps'),
            $reader->bool($d['require_channel_arrival'], 'cadence.require_channel_arrival'),
            $reader->bool($d['opposite_direction_immediate'], 'cadence.opposite_direction_immediate'),
            $reader->bool($d['release_on_booking'], 'cadence.release_on_booking'),
            $reader->bool($d['new_order_immediate_once'], 'cadence.new_order_immediate_once'),
            $reader->bool($d['first_ladder_step_immediate'], 'cadence.first_ladder_step_immediate'),
            $reader->bool($d['relation_violation_bypass'], 'cadence.relation_violation_bypass'),
        );
        $reader->finish('cadence');

        return $config;
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('cadence', FieldType::Group, 'Step cadence', 'One price step per night per day and direction, with the exceptions below.'),
            new Field('cadence.min_hours_between_steps', FieldType::Integer, 'Hours between steps', 'Minimum time before a second step in the same direction on a night.', 24, min: 0, max: 168),
            new Field('cadence.require_channel_arrival', FieldType::Boolean, 'Wait for the channel', 'A second step waits until the previous one was pushed to the sales channels.', true),
            new Field('cadence.opposite_direction_immediate', FieldType::Boolean, 'Reverse at once', 'A step in the other direction does not wait.', true),
            new Field('cadence.release_on_booking', FieldType::Boolean, 'Booking releases a step', 'A new booking allows one more step up the same day; a cancellation one more down.', true),
            new Field('cadence.new_order_immediate_once', FieldType::Boolean, 'New order at once', 'An owner order given today is applied once without waiting.', true),
            new Field('cadence.first_ladder_step_immediate', FieldType::Boolean, 'First ladder step at once', 'The first ladder price of a night does not wait for earlier steps.', true),
            new Field('cadence.relation_violation_bypass', FieldType::Boolean, 'Fix room order at once', 'A ladder room outside the limits of its room relations is corrected without waiting.', true),
        ];
    }
}
