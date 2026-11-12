<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Settings of the owner orders: how long a manual change freezes a night and the values a target
 * order uses when it does not say.
 *
 * Reference: ordens.py (registrar_manual, alvo_da_ordem) and pace.py (noite_fraca, piso_atras_da_ordem,
 * degrau_atras_da_ordem).
 */
final class OrdersConfig
{
    public const ROOT = 'orders';

    /**
     * @param list<int> $descentMarks days before the night at which a weak night goes one descent step down
     */
    public function __construct(
        public readonly int $manualFreezeDays = 7,
        public readonly float $targetPercentile = 50.0,
        public readonly float $strongNightOccupancy = 0.7,
        public readonly float $strongNightAheadOccupancy = 0.5,
        public readonly int $fallbackTriggerDays = 7,
        public readonly array $descentMarks = [7, 5, 3, 1],
        public readonly float $descentReachedSlack = 0.02,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "orders" section; missing keys take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $defaults = $d['defaults'];
        $marks = [];
        foreach ($reader->items($defaults['descent_marks'], 'orders.defaults.descent_marks') as $i => $mark) {
            $marks[] = $reader->int($mark, "orders.defaults.descent_marks.$i");
        }
        $config = new self(
            $reader->int($d['manual_freeze_days'], 'orders.manual_freeze_days'),
            $reader->float($defaults['target_percentile'], 'orders.defaults.target_percentile'),
            $reader->float($defaults['strong_night_occupancy'], 'orders.defaults.strong_night_occupancy'),
            $reader->float($defaults['strong_night_ahead_occupancy'], 'orders.defaults.strong_night_ahead_occupancy'),
            $reader->int($defaults['fallback_trigger_days'], 'orders.defaults.fallback_trigger_days'),
            $marks,
            $reader->float($d['descent_reached_slack'], 'orders.descent_reached_slack'),
        );
        $reader->finish('orders');

        return $config;
    }

    /**
     * The definitions of the "orders" section, in the format of the rule set schema.
     *
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('orders', FieldType::Group, 'Owner orders', 'How owner orders freeze nights and what a target order assumes when it does not say.'),
            new Field('orders.manual_freeze_days', FieldType::Integer, 'Manual change freeze (days)', 'A night the owner changed by hand in the pricing system stays out of the engine for this many days.', 7, min: 1, max: 60, unit: Field::UNIT_DAYS),
            new Field('orders.defaults', FieldType::Group, 'Target order defaults', 'Values a target order uses when it does not set them.'),
            new Field('orders.defaults.target_percentile', FieldType::Number, 'Order percentile', 'Market percentile of a target order that does not name one.', 50.0, min: 0, max: 110),
            new Field('orders.defaults.strong_night_occupancy', FieldType::Number, 'Strong night occupancy', 'From this share of units sold a target order leaves the night to the engine.', 0.7, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field('orders.defaults.strong_night_ahead_occupancy', FieldType::Number, 'Strong night when ahead', 'The same share when the night is ahead of its pace curve.', 0.5, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field('orders.defaults.fallback_trigger_days', FieldType::Integer, 'Fallback trigger (days)', 'Days before the night from which an unsold night may drop to the fallback floor of its order.', 7, min: 0, max: 60, unit: Field::UNIT_DAYS),
            new Field('orders.defaults.descent_marks', FieldType::ListOf, 'Descent marks (days)', 'One descent step of a target order for each of these marks reached, closest last.', [7, 5, 3, 1], min: 1, max: 10, unique: true),
            new Field('orders.defaults.descent_marks.*', FieldType::Integer, 'Mark', min: 0, max: 60, unit: Field::UNIT_DAYS),
            new Field('orders.descent_reached_slack', FieldType::Number, 'Step reached slack', 'A descent step counts as reached when its price is within this share of the live price.', 0.02, min: 0, max: 0.1, unit: Field::UNIT_RATIO),
        ];
    }
}
