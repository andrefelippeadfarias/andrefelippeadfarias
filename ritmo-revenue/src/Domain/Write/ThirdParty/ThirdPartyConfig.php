<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\ThirdParty;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * How long a night changed by someone else stays frozen (section "third_party"): 7 days from the
 * detection by default (the reference's length), or, when switched on, until a person releases it
 * or the night passes. A person can always release a freeze earlier; nothing writes over it while
 * it lasts.
 */
final class ThirdPartyConfig
{
    public const ROOT = 'third_party';

    public function __construct(
        public readonly bool $untilNightPasses = false,
        public readonly int $freezeDays = 7,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "third_party" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self(
            $reader->bool($d['until_night_passes'], 'third_party.until_night_passes'),
            $reader->int($d['freeze_days'], 'third_party.freeze_days'),
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
            new Field('third_party', FieldType::Group, 'Changes made by others', 'A night someone changed in the pricing system or the PMS since the plugin last read it is frozen: the plugin never writes over it.'),
            new Field('third_party.until_night_passes', FieldType::Boolean, 'Freeze until released', 'Keep the night frozen until a person releases it or the night passes, instead of the days below.', false),
            new Field('third_party.freeze_days', FieldType::Integer, 'Freeze for', 'Days a changed night stays frozen after the change is found.', 7, min: 1, max: 60, unit: Field::UNIT_DAYS),
        ];
    }
}
