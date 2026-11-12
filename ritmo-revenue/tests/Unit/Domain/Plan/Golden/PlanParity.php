<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan\Golden;

use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Calendar\Stay\StayConfig;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\OrphanClaim;
use Ritmo\Domain\Plan\OrphanClaimKind;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\Validate\DiscountBandCheck;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Unit\Domain\Relations\Golden\ParityNightCalendar;
use Ritmo\Tests\Unit\Domain\Relations\Golden\ParityRelations;
use Ritmo\Tests\Unit\Domain\Relations\Golden\RecordedHooks;

/**
 * The parity fixture of the plan (tests/fixtures/presets/parity-plan.json), the frozen inputs of
 * tests/fixtures/golden/m2/plan/frozen and the translation of the reference engine's plan entries
 * into the plugin's, with the vocabulary of the golden MANIFEST.
 */
final class PlanParity
{
    public const GOLDEN = 'golden/m2/plan/';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return Fixtures::json(self::GOLDEN . 'MANIFEST.json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function cases(string $file): array
    {
        return Fixtures::json(self::GOLDEN . $file)['cases'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function frozen(string $file): array
    {
        return Fixtures::json(self::GOLDEN . 'frozen/' . $file);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parity(): array
    {
        return Fixtures::json('presets/parity-plan.json');
    }

    public static function config(): PlanConfig
    {
        return PlanConfig::fromArray(self::parity()['plan']);
    }

    public static function stay(): StayRules
    {
        $preset = Fixtures::parityPreset();

        return new StayRules(StayConfig::fromArray(Fixtures::json('presets/parity-stay.json')['stay']), new HolidayCalendar($preset->rules), $preset->roomTypes);
    }

    /**
     * The validator rule of the discount bands with the parity relations; an order releases the
     * band where the case recorded it.
     *
     * @param array<string, string> $releases room-night key => releasing order
     */
    public static function bands(array $releases): DiscountBandCheck
    {
        $config = ParityRelations::config();

        return new DiscountBandCheck(new RelationGraph($config->relations, ParityRelations::rooms()), $config->settings, new ParityNightCalendar(), new RecordedHooks([], null, $releases));
    }

    /**
     * The frozen owner orders plus the synthetic ones of the scenario.
     */
    public static function orders(string $scenario): OrderBook
    {
        $frozen = self::frozen('scenarios.json')['synthetic_orders'];
        $rows = array_merge(self::frozen('orders.json')['ordens'], $frozen['*'], $frozen[$scenario] ?? []);
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }
        $reader = new OrderReader();

        return new OrderBook(array_values(array_map(static fn (array $o) => $reader->read(self::orderRow($o)), $byId)));
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private static function orderRow(array $order): array
    {
        $vocabulary = self::manifest()['vocabulary'];
        $row = [];
        foreach ($order as $key => $value) {
            $name = $vocabulary['order_keys'][$key] ?? null;
            if ($name === null || $value === null) {
                continue;
            }
            $row[$name] = match ($name) {
                'type' => $vocabulary['order_type'][$value],
                'releases' => array_values(array_unique(array_filter(array_map(static fn (string $r): ?string => $vocabulary['release'][$r] ?? null, $value)))),
                'floors', 'top', 'fallback_floors' => array_map('floatval', $value),
                'percentile' => (float) $value,
                default => $value,
            };
        }

        return $row;
    }

    /**
     * The fresh calendar of a frozen pricing calendar, read as the reference's precos_da_coleta.
     */
    public static function calendar(string $file): FreshCalendar
    {
        $nights = [];
        $refreshed = [];
        foreach (self::frozen($file)['rooms'] as $code => $room) {
            $refreshed[$code] = $room['last_refreshed_at'] === null ? null : new \DateTimeImmutable($room['last_refreshed_at']);
            foreach ($room['days'] as $day) {
                $units = (string) ($day['multi_unit_occupancy'] ?? '');
                if (str_contains($units, '/')) {
                    [$sold, $total] = array_map('intval', explode('/', $units));
                } else {
                    [$sold, $total] = [trim((string) ($day['booking_status'] ?? '')) !== '' ? 1 : 0, 1];
                }
                $nights[PlanItem::keyOf((string) $code, Days::date(substr($day['date'], 0, 10)))] = new CalendarNight(
                    $day['price'] === null ? null : (float) $day['price'],
                    $day['min_price'] === null ? null : (float) $day['min_price'],
                    $sold,
                    $total,
                );
            }
        }

        return new FreshCalendar($nights, $refreshed);
    }

    /**
     * @param array<string, mixed> $it an item of type "data" of the reference's plan
     */
    public static function item(array $it): PlanItem
    {
        $motivo = (string) ($it['motivo'] ?? '');
        $byOrder = ($it['excecao'] ?? null) === 'dono' ? ($it['ordem_id'] ?? null) : null;

        return new PlanItem(
            $it['quarto'],
            Days::date($it['data']),
            isset($it['min_price']) ? (float) $it['min_price'] : null,
            isset($it['max_price']) ? (float) $it['max_price'] : null,
            isset($it['min_stay']) ? (int) $it['min_stay'] : null,
            self::reasonOf($motivo),
            [],
            isset($it['preco_antes']) ? new NightState((float) $it['preco_antes']) : null,
            $it['check_in'] ?? null,
            $it['check_out'] ?? null,
            $byOrder,
            isset($it['orfa']) ? self::orphan($it['orfa'], $it['orfa_prova'] ?? null) : null,
            $it['mercado'] ?? null,
            (bool) ($it['manter_reason'] ?? false),
            $motivo,
            preg_match('/' . self::manifest()['vocabulary']['occupancy_pattern'] . '/u', mb_strtolower($motivo)) === 1,
        );
    }

    /**
     * @param array<string, mixed> $it an item of type "apagar"
     */
    public static function deletion(array $it): PlanDeletion
    {
        return new PlanDeletion($it['quarto'], Days::date($it['data']), self::reasonOf((string) $it['motivo']), (string) $it['motivo']);
    }

    /**
     * @param array<string, mixed> $it an item of type "anuncio"
     */
    public static function listing(array $it): ListingChange
    {
        $byOrder = ($it['excecao'] ?? null) === 'dono' ? ($it['ordem_id'] ?? null) : null;

        return new ListingChange($it['quarto'], ListingField::from($it['campo']), (float) $it['valor'], self::reasonOf((string) $it['motivo']), (string) $it['motivo'], $byOrder);
    }

    public static function reasonOf(string $motivo): string
    {
        foreach (self::manifest()['vocabulary']['reasons'] as $marker => $code) {
            if (str_contains($motivo, (string) $marker)) {
                return $code;
            }
        }

        return 'OWNER_ORDER';
    }

    /**
     * @param array<string, mixed>|null $proof
     */
    private static function orphan(string $side, ?array $proof): OrphanClaim
    {
        $kind = OrphanClaimKind::from(self::manifest()['vocabulary']['orphan'][$side]);
        if ($proof === null) {
            return new OrphanClaim($kind);
        }
        $next = $kind === OrphanClaimKind::NextNight;
        $neighbour = $next ? 'seguinte' : 'anterior';
        $int = static fn (string $key): ?int => isset($proof[$key]) ? (int) $proof[$key] : null;

        return new OrphanClaim($kind, new OrphanProof(
            ($proof['fonte'] ?? null) === 'beds24' ? OrphanProof::SOURCE_PMS : OrphanProof::SOURCE_PRICING,
            $next ? OrphanProof::NEXT : OrphanProof::PREVIOUS,
            $int('unidades'),
            $int('noite_numAvail'),
            $int($neighbour . '_numAvail'),
            $int('noite_vendidas'),
            $int('noite_total'),
            $int($neighbour . '_vendidas'),
            $int($neighbour . '_total'),
            isset($proof['lido_em']) ? new \DateTimeImmutable($proof['lido_em']) : null,
            isset($proof['anterior_passada']) ? Days::date($proof['anterior_passada']) : null,
        ));
    }
}
