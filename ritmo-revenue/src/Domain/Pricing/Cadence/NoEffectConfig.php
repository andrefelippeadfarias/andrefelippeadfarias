<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Settings of the no-effect filter ({@see NoEffectFilter}): what counts as "already in force"
 * before a plan is validated. Reference: pace.sem_efeito (the rounding-only part is a lesson of the
 * reference's operation, not in its code; the parity preset switches it off).
 */
final class NoEffectConfig
{
    public const ROOT = 'no_effect';

    public function __construct(
        public readonly bool $dropRoundingOnly = true,
        public readonly float $moneySlack = 1.0,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "no_effect" section; missing keys take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self(
            $reader->bool($d['drop_rounding_only'], 'no_effect.drop_rounding_only'),
            $reader->float($d['money_slack'], 'no_effect.money_slack'),
        );
        $reader->finish(self::ROOT);

        return $config;
    }

    public function filter(): NoEffectFilter
    {
        return new NoEffectFilter($this->dropRoundingOnly, $this->moneySlack);
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('no_effect', FieldType::Group, 'Items without effect', 'Items that would change nothing are dropped before the plan is validated, so they cost no write and hold no step.'),
            new Field('no_effect.drop_rounding_only', FieldType::Boolean, 'Drop rounding-only items', 'An item whose floor and ceiling land on the same whole percentage of the base as the ones in force changes nothing the pricing system applies.', true),
            new Field('no_effect.money_slack', FieldType::Number, 'Same amount within', 'Floors and ceilings this close to the ones in force count as already in force.', 1.0, min: 0, max: 100, unit: Field::UNIT_MONEY),
        ];
    }
}
