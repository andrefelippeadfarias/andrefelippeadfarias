<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Todo;

use DateTimeImmutable;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\State\Provider;

/**
 * The plugin's own to-do items (admin spec 5.12), from stored facts only: each opens while its
 * condition holds and closes itself when it ends. Called on screen load (the integration also
 * calls it after the write and verify stages).
 */
final class TodoGenerator
{
    public const SYNC_NOW = 'sync_now';
    public const CONFIRM_ORIGINS = 'confirm_origins';
    public const SAFETY_CONFIRM = 'safety_confirm';
    public const CONNECTION_TEST = 'connection_test';

    /** Settings key of the setup wizard's facts (safety confirmation). */
    public const SETUP_KEY = 'setup';

    /** Settings key prefix of the connection test results (ModePreconditions). */
    public const TEST_PREFIX = 'connection_test_';

    public const PROVIDERS = ['beds24', 'pricelabs'];

    private const TEST_MAX_AGE_DAYS = 7;

    public function __construct(
        private readonly TodoStore $store,
        private readonly KeyValueStore $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<ChangeRecord> $recentChanges the property's recent change-log rows (any order)
     */
    public function refresh(string $propertyId, string $timezone, RuleSet $rules, array $recentChanges): void
    {
        $now = $this->clock->now();
        $this->condition(self::SAFETY_CONFIRM, '', 'Confirm the safety limits.', !$this->safetyConfirmed(), []);
        $this->condition(self::CONFIRM_ORIGINS, '', 'Confirm the list of booking origins.', !$rules->alerts()->security->originsConfirmed, []);
        foreach (self::PROVIDERS as $provider) {
            $this->condition(self::CONNECTION_TEST, $provider, sprintf('Test the connection to %s.', $provider), $this->needsTest($provider, $now), ['provider' => $provider]);
        }
        $this->syncNow($propertyId, $timezone, $recentChanges);
    }

    private function safetyConfirmed(): bool
    {
        $setup = $this->settings->get(self::SETUP_KEY);

        return is_array($setup) && is_string($setup['safety_confirmed_at'] ?? null) && $setup['safety_confirmed_at'] !== '';
    }

    private function needsTest(string $provider, DateTimeImmutable $now): bool
    {
        $test = $this->settings->get(self::TEST_PREFIX . $provider);
        if (!is_array($test) || ($test['ok'] ?? false) !== true || !is_string($test['at'] ?? null)) {
            return true;
        }

        return (new DateTimeImmutable($test['at']))->modify(sprintf('+%d days', self::TEST_MAX_AGE_DAYS)) < $now;
    }

    /**
     * @param array<string, string> $params
     */
    private function condition(string $kind, string $scope, string $title, bool $holds, array $params): void
    {
        $key = $kind . ($scope === '' ? '' : ':' . $scope);
        if (!$holds) {
            $this->store->closeKeys([$key], $this->clock->now());

            return;
        }
        $this->store->add(new TodoItem('', TodoItem::PLUGIN, $kind, $title, dedupeKey: $key, params: $params), $this->clock->now());
    }

    /**
     * @param list<ChangeRecord> $changes
     */
    private function syncNow(string $propertyId, string $timezone, array $changes): void
    {
        $today = $this->clock->today($timezone);
        $service = new TodoService($this->store, $this->clock);
        $rooms = [];
        foreach ($changes as $change) {
            if ($change->provider !== Provider::Pricing || $change->night === null || $change->undoOf !== null) {
                continue;
            }
            $writtenOn = Days::localDate($change->createdAt, $timezone);
            $days = Days::diff($today, $change->night);
            if (Days::key($writtenOn) === Days::key($today) && $days >= 0 && $days <= 1) {
                $rooms[$change->roomCode] = true;
            }
        }
        foreach (array_keys($rooms) as $room) {
            $service->syncNow($propertyId, (string) $room, $today);
        }
    }
}
