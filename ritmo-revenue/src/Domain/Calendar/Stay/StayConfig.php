<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSetValidator;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * The stay and arrival/departure rules ("stay" section). The neutral defaults switch every rule
 * off: no arrival rule, no closed or open day, no package, no season, nothing pushed by the
 * pricing system.
 */
final class StayConfig
{
    /**
     * @param list<ClosedDayRule> $closedDays
     * @param list<OpenDayRule>   $openDays
     * @param list<Package>       $packages
     * @param list<Season>        $seasons
     * @param list<string>        $keepReasonMarkers lowercase texts
     */
    public function __construct(
        public readonly ArrivalRule $arrival = new ArrivalRule(),
        public readonly OrphanPolicy $orphan = new OrphanPolicy(),
        public readonly int $holidayMinStay = 1,
        public readonly array $closedDays = [],
        public readonly array $openDays = [],
        public readonly array $packages = [],
        public readonly array $seasons = [],
        public readonly PricingSync $pricingSync = new PricingSync(),
        public readonly array $keepReasonMarkers = [],
        public readonly BatchPolicy $batch = new BatchPolicy(),
    ) {
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return StayFields::all();
    }

    /**
     * @param array<string, mixed> $data the "stay" section; missing fields take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $data = Schema::ruleSet()->withDefaults([StayFields::ROOT => $data])[StayFields::ROOT];
        $errors = (new RuleSetValidator())->validateSection(StayFields::ROOT, $data);
        if ($errors === []) {
            $errors = self::crossFieldErrors($data);
        }
        if ($errors !== []) {
            throw new InvalidConfig('stay rules', $errors);
        }

        return self::build($data);
    }

    /**
     * @param array<string, mixed> $d a structurally valid section
     *
     * @return list<ValidationError>
     */
    private static function crossFieldErrors(array $d): array
    {
        $errors = [];
        $rule = $d['arrival_rule'];
        if ($rule['valid_from'] !== null && $rule['valid_to'] !== null && $rule['valid_to'] < $rule['valid_from']) {
            $errors[] = new ValidationError('stay.arrival_rule.valid_to', ValidationError::ORDER, 'must not be before the first night');
        }
        foreach ($d['closed_days'] as $i => $closed) {
            foreach (array_keys($closed['open_dates']) as $date) {
                if (!Days::isValid((string) $date) || Days::weekday(Days::date((string) $date)) !== $closed['weekday']) {
                    $errors[] = new ValidationError("stay.closed_days.$i.open_dates.$date", ValidationError::FORMAT, 'must be a date of the closed weekday');
                }
            }
        }
        foreach ($d['seasons'] as $i => $season) {
            if ($season['end'] < $season['start']) {
                $errors[] = new ValidationError("stay.seasons.$i.end", ValidationError::ORDER, 'must not be before the first night');
            }
        }
        foreach ($d['packages'] as $i => $package) {
            $dates = array_column($package['nights'], 'date');
            if (count($dates) !== count(array_unique($dates))) {
                $errors[] = new ValidationError("stay.packages.$i.nights", ValidationError::UNIQUE, 'must not repeat a night');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function build(array $d): self
    {
        return new self(
            self::arrivalRule($d['arrival_rule']),
            new OrphanPolicy($d['orphan']['next_night'], $d['orphan']['on_holidays']),
            $d['holiday_min_stay'],
            array_map(self::closedDay(...), $d['closed_days']),
            array_map(static fn (array $o): OpenDayRule => new OpenDayRule($o['weekday'], $o['rooms']), $d['open_days']),
            array_map(self::package(...), $d['packages']),
            array_map(self::season(...), $d['seasons']),
            new PricingSync($d['pricing_sync']['min_stay_rooms'], $d['pricing_sync']['max_stay_rooms'], $d['pricing_sync']['arrival_departure_rooms']),
            array_map('mb_strtolower', $d['keep_reason_markers']),
            new BatchPolicy($d['batch']['urgent_days'], $d['batch']['max_ranges']),
        );
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function arrivalRule(array $r): ArrivalRule
    {
        $byWeekday = [];
        foreach (range(0, 6) as $weekday) {
            $byWeekday[$weekday] = $r['by_weekday'][$weekday];
        }

        return new ArrivalRule(
            $r['enabled'],
            $r['valid_from'] === null ? null : Days::date($r['valid_from']),
            $r['valid_to'] === null ? null : Days::date($r['valid_to']),
            $byWeekday,
            self::weekdayTables($r['properties']),
            self::weekdayTables($r['rooms']),
            array_map(static fn (array $days): array => array_values($days), $r['held_weekdays']),
        );
    }

    /**
     * @param array<int|string, array<int|string, int>> $tables
     *
     * @return array<string, array<int, int>>
     */
    private static function weekdayTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $owner => $table) {
            foreach ($table as $weekday => $nights) {
                $out[(string) $owner][(int) $weekday] = $nights;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function closedDay(array $c): ClosedDayRule
    {
        $side = $c['orphan_side'];

        return new ClosedDayRule(
            $c['weekday'],
            Restriction::from($c['restriction']),
            $c['rooms'],
            $c['except_rooms'],
            $c['min_stay'],
            array_map(static fn (string $r): Restriction => Restriction::from($r), $c['open_dates']),
            new OrphanSide(
                $side['enabled'],
                $side['on_holidays'],
                Restriction::from($side['when_closed_day_sold_out']),
                Restriction::from($side['when_previous_sold_out']),
            ),
        );
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function package(array $p): Package
    {
        $nights = [];
        foreach ($p['nights'] as $n) {
            $nights[$n['date']] = new PackageNight(
                Days::date($n['date']),
                $n['min_stay'],
                $n['max_stay'],
                $n['restriction'] === null ? null : Restriction::from($n['restriction']),
            );
        }

        return new Package($p['name'], $p['rooms'], $nights);
    }

    /**
     * @param array<string, mixed> $s
     */
    private static function season(array $s): Season
    {
        return new Season($s['name'], DateRange::fromStrings($s['start'], $s['end']), array_values($s['weekdays']), $s['min_stay']);
    }
}
