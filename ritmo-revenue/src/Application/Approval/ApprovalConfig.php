<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * How long a plan may wait for a person (section "approval"): never past the property's midnight,
 * and never longer than the calendar it was built on stays fresh. The approved write reads the
 * calendar again anyway; this only stops old plans from being shown as current.
 */
final class ApprovalConfig
{
    public const ROOT = 'approval';

    public function __construct(public readonly int $maxCalendarAgeHours = 6)
    {
    }

    /**
     * @param array<string, mixed> $data the "approval" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self($reader->int($d['max_calendar_age_hours'], 'approval.max_calendar_age_hours'));
        $reader->finish(self::ROOT);

        return $config;
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('approval', FieldType::Group, 'Plans waiting for approval', 'A plan proposed for approval expires at the property\'s midnight or when its calendar gets old, whichever comes first.'),
            new Field('approval.max_calendar_age_hours', FieldType::Integer, 'Calendar valid for', 'Hours after the calendar was read that a plan built on it may still be approved.', 6, min: 1, max: 24),
        ];
    }
}
