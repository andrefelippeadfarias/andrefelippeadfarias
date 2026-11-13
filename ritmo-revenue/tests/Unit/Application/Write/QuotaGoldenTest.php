<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Quota\AttemptLog;
use Ritmo\Application\Write\Quota\AttemptOutcome;
use Ritmo\Application\Write\Quota\AttemptRefused;
use Ritmo\Application\Write\Quota\QuotaConfig;
use Ritmo\Application\Write\Quota\RecalcAttempt;
use Ritmo\Application\Write\Quota\RecalcQuota;
use Ritmo\Application\Write\Quota\RollingWindow;
use Ritmo\Tests\Support\Fixtures;

/**
 * Parity of the recalculation quota with the reference (cota_recalculo.situacao, abre_em and the
 * recording rules of registrar), on the frozen cases of tests/fixtures/golden/m3/write/. The run
 * start and the next full run of each case come from the reference's routine schedule in the
 * write parity fixture (the plugin takes them from its scheduler).
 *
 * Left out on purpose: the registrar case "id_in_seconds_is_refused" checks the format of the
 * reference's tool-result ids (epoch milliseconds), which the plugin never receives (WRITE.md,
 * divergence C11).
 */
final class QuotaGoldenTest extends TestCase
{
    private const OUTCOMES = ['ok' => AttemptOutcome::Ok, 'falha' => AttemptOutcome::Failure, '429' => AttemptOutcome::RateLimited];

    private const NOT_APPLICABLE = ['id_in_seconds_is_refused'];

    /**
     * @return array<string, array{array<string, mixed>, mixed}>
     */
    private static function cases(string $file): array
    {
        $cases = [];
        foreach (Fixtures::json('golden/m3/write/' . $file)['cases'] as $case) {
            if (!in_array($case['name'], self::NOT_APPLICABLE, true)) {
                $cases[$case['name']] = [$case['input'], $case['expected']];
            }
        }

        return $cases;
    }

    public static function situations(): array
    {
        return self::cases('cota_recalculo.situacao.json');
    }

    public static function windows(): array
    {
        return self::cases('cota_recalculo.abre_em.json');
    }

    public static function recordings(): array
    {
        return self::cases('cota_recalculo.registrar.json');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('situations')]
    public function testTheVerdictIsTheReferences(array $input, array $expected): void
    {
        $now = AttemptLog::instant($input['now']);
        $runStart = $input['run_start'] === null ? self::lastRoutine($now) : AttemptLog::instant($input['run_start']);
        $attempts = array_map(static fn (array $a): RecalcAttempt => new RecalcAttempt($a['quarto'], AttemptLog::instant($a['em']), self::OUTCOMES[$a['resultado']], $a['erro'] ?? null), $input['attempts']);
        $quota = new RecalcQuota(new QuotaConfig(futureSlackSeconds: $input['future_slack_seconds']));

        $verdict = $quota->verdict($attempts, $input['room'], $now, $runStart, self::nextFullRun($now));

        self::assertSame($expected['run_start'], self::iso($runStart), 'the parity schedule gives the reference\'s run start');
        self::assertSame($expected['allowed'], $verdict->allowed);
        self::assertSame($expected['blocked_by'], $verdict->blockedBy);
        self::assertSame($expected['used'], $verdict->used);
        self::assertSame($expected['left'], $verdict->left);
        self::assertSame(
            $expected['by_result'],
            ['ok' => $verdict->byResult['ok'], 'falha' => $verdict->byResult['failure'], '429' => $verdict->byResult['rate_limited']]
        );
        self::assertSame($expected['room_slot_opens_at'], self::iso($verdict->roomSlotOpensAt));
        self::assertSame($expected['account_slot_opens_at'], self::iso($verdict->accountSlotOpensAt));
        self::assertSame($expected['account_429_wait_until'], self::iso($verdict->account429WaitUntil));
        self::assertSame($expected['account_last_minute'], $verdict->accountLastMinute);
        self::assertSame($expected['morning_slot_warning'], $verdict->morningSlotWarning);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('windows')]
    public function testTheWindowReopensWhenTheReferenceSays(array $input, ?string $expected): void
    {
        $instants = array_map(static fn (string $at): DateTimeImmutable => AttemptLog::instant($at), $input['instants']);

        self::assertSame($expected, self::iso(RollingWindow::opensAt($instants, $input['limit'], $input['window_seconds'])));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('recordings')]
    public function testAttemptsAreRecordedByTheReferencesRules(array $input, array $expected): void
    {
        $now = AttemptLog::instant($input['now']);
        $log = new AttemptLog(300);
        $stored = $outcomes = [];
        foreach ($input['operations'] as $op) {
            try {
                $attempt = self::attempt($op);
                $kept = $log->accept($stored, $attempt, $now);
                if ($kept !== null) {
                    $stored[] = $kept;
                }
                $shown = $kept ?? self::sameAs($stored, $attempt);
                $outcomes[] = ['recorded' => $kept !== null, 'at' => self::iso($shown->at), 'error_kept' => $shown->error];
            } catch (AttemptRefused) {
                $outcomes[] = ['refused' => true];
            }
        }

        self::assertSame($expected['outcomes'], $outcomes);
        self::assertSame($expected['stored'], array_map(self::stored(...), $stored));
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function attempt(array $op): RecalcAttempt
    {
        $outcome = self::OUTCOMES[$op['result']] ?? throw new AttemptRefused('unknown result');
        if (isset($op['id_ms'])) {
            $at = (new DateTimeImmutable('@' . intdiv((int) $op['id_ms'], 1000)))->setTimezone(new DateTimeZone('UTC'));

            return new RecalcAttempt($op['room'], $at, $outcome, $op['error'] ?? null, (string) $op['id_ms']);
        }

        return new RecalcAttempt($op['room'], AttemptLog::instant($op['at']), $outcome, $op['error'] ?? null);
    }

    /**
     * @param list<RecalcAttempt> $stored
     */
    private static function sameAs(array $stored, RecalcAttempt $attempt): RecalcAttempt
    {
        foreach ($stored as $other) {
            if ($other->responseId !== null && $other->responseId === $attempt->responseId || ($other->listing === $attempt->listing && $other->at == $attempt->at)) {
                return $other;
            }
        }

        return $attempt;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stored(RecalcAttempt $attempt): array
    {
        $row = ['quarto' => $attempt->listing, 'em' => self::iso($attempt->at), 'resultado' => array_search($attempt->outcome, self::OUTCOMES, true)];
        if ($attempt->error !== null) {
            $row['erro'] = $attempt->error;
        }
        if ($attempt->responseId !== null) {
            $row['id_ms'] = (int) $attempt->responseId;
        }

        return $row;
    }

    private static function lastRoutine(DateTimeImmutable $now): DateTimeImmutable
    {
        $schedule = Fixtures::json('presets/parity-write.json')['run_schedule'];
        $tz = new DateTimeZone($schedule['timezone']);
        $local = $now->setTimezone($tz);
        $starts = [];
        foreach ([0, 1] as $back) {
            foreach ($schedule['routines'] as $time) {
                $start = new DateTimeImmutable($local->modify(sprintf('-%d day', $back))->format('Y-m-d') . ' ' . $time, $tz);
                if ($start <= $local) {
                    $starts[] = $start;
                }
            }
        }

        return max($starts)->setTimezone(new DateTimeZone('UTC'));
    }

    private static function nextFullRun(DateTimeImmutable $now): DateTimeImmutable
    {
        $schedule = Fixtures::json('presets/parity-write.json')['run_schedule'];
        $tz = new DateTimeZone($schedule['timezone']);
        $local = $now->setTimezone($tz);
        $next = new DateTimeImmutable($local->format('Y-m-d') . ' ' . $schedule['full_run'], $tz);

        return ($next <= $local ? $next->modify('+1 day') : $next)->setTimezone(new DateTimeZone('UTC'));
    }

    private static function iso(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
