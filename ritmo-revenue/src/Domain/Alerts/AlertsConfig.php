<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * The alert rules (section "alerts"; the operations spec nests it under "operations"). Recipients
 * and e-mail limits are site settings, never part of a preset.
 */
final class AlertsConfig
{
    public const ROOT = 'alerts';

    private const CHANNEL_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,31}$/';

    public function __construct(
        public readonly SecurityConfig $security = new SecurityConfig(),
        public readonly BelowExpectedConfig $belowExpected = new BelowExpectedConfig(),
        public readonly int $inventoryMaxNightsListed = 50,
        public readonly int $connectorDownEscalateRuns = 3,
    ) {
    }

    /**
     * @param array<string, mixed> $data the "alerts" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $r = new ConfigReader(self::fields());
        $d = $r->open(self::ROOT, $data);
        $s = $d['security'];
        $origins = [];
        foreach ($r->items($s['known_origins'], 'alerts.security.known_origins') as $i => $origin) {
            $origins[] = $r->string($origin, "alerts.security.known_origins.$i");
        }
        $b = $d['below_expected'];
        $factors = [];
        foreach ($r->items($b['channel_factors'], 'alerts.below_expected.channel_factors') as $property => $channels) {
            foreach ($r->items($channels, "alerts.below_expected.channel_factors.$property") as $channel => $factor) {
                $factors[(string) $property][(string) $channel] = $r->float($factor, "alerts.below_expected.channel_factors.$property.$channel");
            }
        }
        $threshold = $r->float($b['threshold'], 'alerts.below_expected.threshold');
        $critical = $r->float($b['critical_threshold'], 'alerts.below_expected.critical_threshold');
        if ($critical > $threshold) {
            $r->add('alerts.below_expected.critical_threshold', ValidationError::ORDER, 'must not be above the alert threshold');
        }
        $config = new self(
            new SecurityConfig(
                $r->bool($s['enabled'], 'alerts.security.enabled'),
                $origins,
                $r->bool($s['origins_confirmed'], 'alerts.security.origins_confirmed'),
                $r->bool($s['zero_value'], 'alerts.security.zero_value'),
            ),
            new BelowExpectedConfig(
                $r->bool($b['enabled'], 'alerts.below_expected.enabled'),
                $threshold,
                $critical,
                $r->float($b['default_factor'], 'alerts.below_expected.default_factor'),
                PriceBasis::from((string) $r->choice($b['price_basis'], 'alerts.below_expected.price_basis')),
                $factors,
            ),
            $r->int($d['inventory_max_nights_listed'], 'alerts.inventory_max_nights_listed'),
            $r->int($d['connector_down_escalate_runs'], 'alerts.connector_down_escalate_runs'),
        );
        $r->finish(self::ROOT);

        return $config;
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        $property = '/^(\*|' . Identifier::CODE_BODY . ')$/';

        return [
            new Field('alerts', FieldType::Group, 'Alerts', 'What the runs watch and how they warn.'),
            new Field('alerts.security', FieldType::Group, 'Security watch', 'Every run checks the bookings created, changed or cancelled since the previous run.'),
            new Field('alerts.security.enabled', FieldType::Boolean, 'Watch bookings', 'Unknown origins and bookings without value raise critical alerts.', true),
            new Field('alerts.security.known_origins', FieldType::ListOf, 'Known origins', 'Who may create or change bookings in the PMS (channel integrations, staff logins). Compared without case.', [], max: 50, unique: true),
            new Field('alerts.security.known_origins.*', FieldType::Text, 'Origin', required: true, min: 1, max: 64),
            new Field('alerts.security.origins_confirmed', FieldType::Boolean, 'The list of origins is confirmed', 'Until it is, the unknown-origin check only reports the origins it sees.', false),
            new Field('alerts.security.zero_value', FieldType::Boolean, 'Bookings without value', 'A confirmed or new booking with no total, or a total of zero, raises a critical alert.', true),
            new Field('alerts.below_expected', FieldType::Group, 'Bookings below expected', 'A booking worth less than its nights\' PMS prices times the share its channel carries.'),
            new Field('alerts.below_expected.enabled', FieldType::Boolean, 'Check bookings below expected', 'Turn on after setting the channel factors.', false),
            new Field('alerts.below_expected.threshold', FieldType::Number, 'Alert under', 'Ratio of the booking total to the expected value.', 0.70, min: 0.1, max: 1, unit: Field::UNIT_RATIO),
            new Field('alerts.below_expected.critical_threshold', FieldType::Number, 'Critical under', 'Below this ratio the booking is almost always a mistake.', 0.50, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field('alerts.below_expected.default_factor', FieldType::Number, 'Factor of other channels', 'Share of the PMS price a booking of a channel without its own factor carries.', 1.0, min: 0.05, max: 2, unit: Field::UNIT_RATIO),
            new Field('alerts.below_expected.price_basis', FieldType::Choice, 'Compare with the price', 'At booking: the PMS price read last before the booking was made. Current: the price now.', PriceBasis::AtBooking->value, enum: array_map(static fn (PriceBasis $b): string => $b->value, PriceBasis::cases())),
            new Field('alerts.below_expected.channel_factors', FieldType::Map, 'Channel factors', 'Per property (or * for every property): channel => share of the PMS price a booking of that channel carries.', [], max: 100, pattern: $property),
            new Field('alerts.below_expected.channel_factors.*', FieldType::Map, 'Channels', required: true, max: 20, pattern: self::CHANNEL_PATTERN),
            new Field('alerts.below_expected.channel_factors.*.*', FieldType::Number, 'Factor', required: true, min: 0.05, max: 2, unit: Field::UNIT_RATIO),
            new Field('alerts.inventory_max_nights_listed', FieldType::Integer, 'Nights listed in an inventory alert', 'At most this many nights in the details of one inventory alert.', 50, min: 5, max: 366, unit: Field::UNIT_NIGHTS),
            new Field('alerts.connector_down_escalate_runs', FieldType::Integer, 'E-mail again after', 'A connection still down after this many runs is e-mailed once more.', 3, min: 1, max: 20, unit: 'runs'),
        ];
    }
}
