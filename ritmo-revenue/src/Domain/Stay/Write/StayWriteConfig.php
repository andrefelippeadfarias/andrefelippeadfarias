<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * The "stay.write" section read and checked.
 */
final class StayWriteConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly int $horizonDays = 60,
        public readonly int $maxChangesPerRun = 300,
        public readonly int $revertLimit = 2,
        public readonly bool $raiseOwnStays = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data what sits under stay.write; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        // The section's parent ("stay") belongs to the stay rules; here it only frames the reader.
        $reader = new ConfigReader([new Field('stay', FieldType::Group, 'Stay'), ...StayWriteFields::all()]);
        $p = StayWriteFields::ROOT;
        $d = $reader->open('stay', ['write' => $data])['write'];
        $config = new self(
            $reader->bool($d['enabled'], "$p.enabled"),
            $reader->int($d['horizon_days'], "$p.horizon_days"),
            $reader->int($d['max_changes_per_run'], "$p.max_changes_per_run"),
            $reader->int($d['revert_limit'], "$p.revert_limit"),
            $reader->bool($d['raise_own_stays'], "$p.raise_own_stays"),
        );
        $reader->finish('stay.write');

        return $config;
    }
}
