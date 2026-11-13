<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\SecurityConfig;
use Ritmo\Domain\Alerts\SecurityFinding;
use Ritmo\Domain\Alerts\SecurityWatch;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Hold\CancellationWave;
use Ritmo\Domain\Write\Hold\WaveConfig;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Unit\Domain\Operations\ReferenceBookings;

/**
 * Parity of the security watch with the reference (vigia_seguranca.classificar) on the 17
 * goldens of tests/fixtures/golden/m3/ops/ (O22 to O24, O49): kind, booking ids, room, dates,
 * status, channel, origin and burst members; the texts are the plugin's own.
 */
final class SecurityWatchGoldenTest extends TestCase
{
    private const KINDS = [
        'origem desconhecida' => 'unknown_origin',
        'reserva sem valor' => 'zero_value',
        'reserva alterada sem valor' => 'zero_value_modified',
        'rajada de cancelamentos' => 'cancellation_burst',
    ];

    /**
     * @return array<string, array{array<string, mixed>, list<array<string, mixed>>}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (Fixtures::json('golden/m3/ops/vigia_seguranca.classificar.json')['cases'] as $case) {
            $cases[$case['name']] = [$case['input'], $case['expected']];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed>       $input
     * @param list<array<string, mixed>> $expected
     */
    #[DataProvider('cases')]
    public function testTheFindingsMatchTheReference(array $input, array $expected): void
    {
        $par = $input['par'];
        $watch = new SecurityWatch(
            new SecurityConfig(true, $par['origens_conhecidas'], true, true),
            new CancellationWave(new WaveConfig($par['rajada_cancelamentos'], $par['rajada_horas'])),
        );
        $findings = $watch->inspect(ReferenceBookings::all($input['reservas']), new DateTimeImmutable($input['desde']));

        self::assertSame(array_map(self::expected(...), $expected), array_map(self::actual(...), $findings));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function expected(array $row): array
    {
        $kind = self::KINDS[$row['alerta']];
        if ($kind === 'cancellation_burst') {
            return ['kind' => $kind, 'channel' => $row['canal'], 'members' => array_map('strval', $row['reservas'])];
        }

        return ['kind' => $kind, 'id' => (string) $row['id'], 'room' => $row['quarto'], 'arrival' => $row['chegada'], 'departure' => $row['saida'], 'status' => $row['status'], 'channel' => $row['canal'], 'origin' => $row['origem']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function actual(SecurityFinding $finding): array
    {
        if ($finding->wave !== null) {
            return ['kind' => $finding->kind->value, 'channel' => $finding->wave->channel, 'members' => $finding->wave->bookingRefs];
        }
        $b = $finding->booking;
        self::assertNotNull($b);

        return ['kind' => $finding->kind->value, 'id' => $b->pmsRef, 'room' => $b->roomCode, 'arrival' => Days::key($b->arrival), 'departure' => Days::key($b->departure), 'status' => $b->status->value, 'channel' => $b->channel, 'origin' => $b->origin];
    }
}
