<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;
use Ritmo\Domain\Write\Channel\ChannelArrival;

/**
 * Confirmation settings (section "verify"): how long a written pace step may wait to reach the
 * channels before it is an alert. The default is the reference's (a push older than 30 hours is
 * an alert).
 */
final class VerifyConfig
{
    public const ROOT = 'verify';

    public function __construct(public readonly int $deliveryOverdueHours = 30)
    {
    }

    /**
     * @param array<string, mixed> $data the "verify" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self($reader->int($d['delivery_overdue_hours'], 'verify.delivery_overdue_hours'));
        $reader->finish(self::ROOT);

        return $config;
    }

    public function channelArrival(): ChannelArrival
    {
        return new ChannelArrival($this->deliveryOverdueHours);
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            new Field('verify', FieldType::Group, 'Confirmation of writes', 'Every write is read back; a pace step counts as delivered only when the room was pushed after it and the PMS shows its price.'),
            new Field('verify.delivery_overdue_hours', FieldType::Integer, 'Delivery overdue after', 'Hours a written step may wait for the push and the PMS price before it is reported as overdue.', 30, min: 1, max: 72),
        ];
    }
}
