<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * Cancellation waves (section "waves"): N or more cancellations of one channel, each within H
 * hours of the next, hold the price cuts until a person confirms them (or the hold hours pass,
 * when set). The defaults are the reference's (3 within 3 hours), held until acknowledged.
 */
final class WaveConfig
{
    public const ROOT = 'waves';

    public function __construct(
        public readonly int $minCancellations = 3,
        public readonly int $windowHours = 3,
        public readonly ?int $holdHours = null,
        public readonly HoldScope $scope = HoldScope::Property,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "waves" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $config = new self(
            $reader->int($d['min_cancellations'], 'waves.min_cancellations'),
            $reader->int($d['window_hours'], 'waves.window_hours'),
            $reader->nullableInt($d['hold_hours'], 'waves.hold_hours'),
            HoldScope::from((string) $reader->choice($d['scope'], 'waves.scope')),
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
            new Field('waves', FieldType::Group, 'Cancellation waves', 'Several cancellations of one channel in a short time can mean a compromised channel account or a property hidden from search: price cuts wait for a person to confirm.'),
            new Field('waves.min_cancellations', FieldType::Integer, 'Cancellations in a wave', 'How many cancellations of the same channel make a wave.', 3, min: 2, max: 20),
            new Field('waves.window_hours', FieldType::Integer, 'Within hours', 'Each cancellation of a wave is at most this many hours after the previous one.', 3, min: 1, max: 24),
            new Field('waves.hold_hours', FieldType::Integer, 'Hold for hours', 'Release the hold this many hours after the last cancellation of the wave. Empty: only a person releases it.', null, nullable: true, min: 1, max: 168),
            new Field('waves.scope', FieldType::Choice, 'Hold cuts on', 'The whole property, or only the nights of the cancelled bookings. Openings in the PMS are held on the nights of the cancelled bookings either way.', HoldScope::Property->value, enum: array_map(static fn (HoldScope $s): string => $s->value, HoldScope::cases())),
        ];
    }
}
