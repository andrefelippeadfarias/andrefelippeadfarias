<?php

declare(strict_types=1);

namespace Ritmo\Application\Mode;

use DateTimeImmutable;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\ModesConfig;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;
use Ritmo\Infrastructure\Schedule\AlertLedger;

/**
 * The conditions a property must meet, at the moment of the change, to move up a mode (operations
 * spec 6.1). Each unmet condition is a code, its values and an English sentence (the screens
 * translate the code); the change is refused while any is unmet.
 *
 * Shadow to approval: the emergency switch released; no open connector_down or write_failed; the
 * last modes.min_shadow_runs runs of the property completed in shadow mode without the plan being
 * refused as a whole, the newest within 24 hours; both connections tested OK within 24 hours.
 * Approval to autonomous: the switch released; no write_failed in the last 7 days; no
 * cancellation wave holding cuts.
 *
 * Divergences from the spec (OPERATIONS.md): the pricing calendar read is not required (the plugin
 * decides and confirms from the date rules without it) and the secrets vault is not checked
 * apart (a passing connection test reads the secrets through it).
 */
final class ModePreconditions
{
    /** Store key of the last connection test of a provider ("<prefix><provider>"). */
    public const CONNECTION_TEST_PREFIX = 'connection_test_';

    /** The providers whose connection test must have passed. */
    public const PROVIDERS = ['beds24', 'pricelabs'];

    private const DAY_SECONDS = 86400;

    private const WRITE_FAILED_DAYS = 7;

    public function __construct(
        private readonly RunRepository $runs,
        private readonly AlertLedger $alerts,
        private readonly WaveHoldRepository $holds,
        private readonly EmergencySwitch $switch,
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<array{code: string, params: array<string, int|string>, text: string}> the conditions not met
     */
    public function toApproval(Property $property, ModesConfig $config): array
    {
        $now = $this->clock->now();
        $unmet = $this->common();
        foreach ($this->alerts->active($property->id, [AlertCode::ConnectorDown->value, AlertCode::WriteFailed->value]) as $alert) {
            $unmet[] = self::unmet('alert_open', ['alert' => $alert->code], sprintf('The alert %s is open: resolve it first.', $alert->code));
        }
        if ($config->minShadowRuns > 0) {
            $last = array_slice(array_values(array_filter(
                $this->runs->latest($property->id, 50),
                static fn (array $run): bool => $run['status'] !== RunRepository::RUNNING,
            )), 0, $config->minShadowRuns);
            $good = array_filter($last, static fn (array $run): bool => $run['status'] === RunRepository::COMPLETED
                && $run['mode'] === PropertyMode::Shadow
                && ($run['summary']['validate']['refused'] ?? false) !== true);
            if (count($good) < $config->minShadowRuns) {
                $unmet[] = self::unmet('shadow_runs', ['needed' => $config->minShadowRuns, 'done' => count($good)], sprintf('The last %d runs must have completed in shadow mode with a plan (%d did).', $config->minShadowRuns, count($good)));
            } elseif (!self::within($last[0]['started_at'], $now, self::DAY_SECONDS)) {
                $unmet[] = self::unmet('shadow_run_old', [], 'The newest completed shadow run is older than 24 hours: run one first.');
            }
        }
        foreach (self::PROVIDERS as $provider) {
            $test = $this->store->get(self::CONNECTION_TEST_PREFIX . $provider);
            $at = is_array($test) && is_string($test['at'] ?? null) ? new DateTimeImmutable($test['at']) : null;
            if (!is_array($test) || ($test['ok'] ?? false) !== true || $at === null || !self::within($at, $now, self::DAY_SECONDS)) {
                $unmet[] = self::unmet('connection_untested', ['provider' => $provider], sprintf('Test the %s connection first: it must have passed within 24 hours.', $provider));
            }
        }

        return $unmet;
    }

    /**
     * @return list<array{code: string, params: array<string, int|string>, text: string}> the conditions not met
     */
    public function toAutonomous(Property $property): array
    {
        $unmet = $this->common();
        $since = $this->clock->now()->modify(sprintf('-%d days', self::WRITE_FAILED_DAYS));
        foreach ($this->alerts->active($property->id, [AlertCode::WriteFailed->value]) as $alert) {
            $unmet[] = self::unmet('write_failed_open', [], 'A write failed and its alert is open: resolve it first.');
            break;
        }
        foreach ($this->alerts->since($property->id, AlertCode::WriteFailed->value, $since) as $alert) {
            $unmet[] = self::unmet('write_failed_recent', ['days' => self::WRITE_FAILED_DAYS, 'date' => $alert->createdAt->format('Y-m-d')], sprintf('A write failed in the last %d days (%s).', self::WRITE_FAILED_DAYS, $alert->createdAt->format('Y-m-d')));
            break;
        }
        if ($this->holds->open($property->id) !== null) {
            $unmet[] = self::unmet('wave_hold', [], 'A cancellation wave is holding price cuts: release it first.');
        }

        return $unmet;
    }

    /**
     * @return list<array{code: string, params: array<string, int|string>, text: string}>
     */
    private function common(): array
    {
        return $this->switch->isEngaged() ? [self::unmet('switch_engaged', [], 'The emergency switch is engaged.')] : [];
    }

    /**
     * @param array<string, int|string> $params
     *
     * @return array{code: string, params: array<string, int|string>, text: string}
     */
    public static function unmet(string $code, array $params, string $text): array
    {
        return ['code' => $code, 'params' => $params, 'text' => $text];
    }

    private static function within(DateTimeImmutable $at, DateTimeImmutable $now, int $seconds): bool
    {
        return $now->getTimestamp() - $at->getTimestamp() <= $seconds;
    }
}
