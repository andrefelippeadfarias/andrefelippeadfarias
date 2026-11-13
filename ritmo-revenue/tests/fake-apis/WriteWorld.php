<?php

declare(strict_types=1);

namespace RitmoFakeApis;

/**
 * The writable side of the simulator, off by default (the M1/M2 checks see the read-only world).
 * Turned on by POST /__writes, it keeps, in a file next to the log:
 * - the date rules of each listing (starting from the scenario's), merged per date by a write and
 *   removed whole by a deletion;
 * - the values written to the PMS calendar, per room and night;
 * - the recalculations of each listing (for the 3 per 24 h and 10 per minute limits; failures
 *   count) and the listings that answer "pricing data not available" until recalculated;
 * - the listing changes;
 * - every write body received, in order ("writes"), so a test can assert what never arrived.
 */
final class WriteWorld
{
    private readonly string $file;

    /** @var array<string, mixed> */
    private array $state;

    public function __construct(string $logFile)
    {
        $this->file = $logFile . '.writes';
        $raw = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        $state = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $this->state = is_array($state) ? $state : [];
    }

    public function enabled(): bool
    {
        return ($this->state['enabled'] ?? false) === true;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $scenarioOverrides listing => the scenario's rules
     * @param list<string>                              $notReady          listings not priced yet
     */
    public function enable(array $scenarioOverrides, array $notReady): void
    {
        $rules = [];
        foreach ($scenarioOverrides as $listing => $list) {
            foreach ($list as $rule) {
                $rules[$listing][$rule['date']] = array_filter($rule, static fn (mixed $v): bool => $v !== null);
            }
        }
        $this->state = ['enabled' => true, 'overrides' => $rules, 'pms' => [], 'refreshes' => [], 'not_ready' => array_values($notReady), 'listings' => [], 'writes' => []];
        $this->save();
    }

    /**
     * @return array<string, mixed> the whole state, for the tests
     */
    public function dump(): array
    {
        return $this->state;
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $body
     */
    public function logWrite(string $method, string $path, mixed $body): void
    {
        $this->state['writes'][] = ['method' => $method, 'path' => $path, 'body' => $body];
        $this->save();
    }

    /**
     * @return array<string, list<array<string, mixed>>> listing => rules in date order
     */
    public function overrides(): array
    {
        $out = [];
        foreach ($this->state['overrides'] ?? [] as $listing => $rules) {
            ksort($rules);
            $out[$listing] = array_values(array_map(static fn (string $date, array $rule): array => ['date' => $date] + $rule, array_keys($rules), $rules));
        }

        return $out;
    }

    /**
     * @param array<string, string> $rule
     *
     * @return array<string, mixed> the rule stored for the date after the merge
     */
    public function mergeRule(string $listing, array $rule): array
    {
        $date = $rule['date'];
        unset($rule['date']);
        $stored = array_merge($this->state['overrides'][$listing][$date] ?? [], $rule);
        $this->state['overrides'][$listing][$date] = $stored;

        return ['date' => $date] + $stored;
    }

    public function deleteRule(string $listing, string $date): void
    {
        unset($this->state['overrides'][$listing][$date]);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> room id => night => values written
     */
    public function calendar(): array
    {
        return $this->state['pms'] ?? [];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function writeNight(string $roomId, string $night, array $values): void
    {
        $this->state['pms'][$roomId][$night] = array_merge($this->state['pms'][$roomId][$night] ?? [], $values);
    }

    public function isNotReady(string $listing): bool
    {
        return in_array($listing, $this->state['not_ready'] ?? [], true);
    }

    /**
     * Records a recalculation and says whether the limits let it through (failures count too).
     */
    public function refresh(string $listing, int $now): bool
    {
        $refreshes = $this->state['refreshes'] ?? [];
        $ofListing = array_filter($refreshes, static fn (array $r): bool => $r['listing'] === $listing && $r['at'] > $now - 86400);
        $lastMinute = array_filter($refreshes, static fn (array $r): bool => $r['at'] > $now - 60);
        $allowed = count($ofListing) < 3 && count($lastMinute) < 10;
        $this->state['refreshes'][] = ['listing' => $listing, 'at' => $now, 'allowed' => $allowed];
        if ($allowed) {
            $this->state['not_ready'] = array_values(array_diff($this->state['not_ready'] ?? [], [$listing]));
        }

        return $allowed;
    }

    /**
     * @param array<string, float> $fields
     */
    public function updateListing(string $listing, array $fields): void
    {
        $this->state['listings'][$listing] = array_merge($this->state['listings'][$listing] ?? [], $fields);
    }

    public function save(): void
    {
        file_put_contents($this->file, json_encode($this->state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * Forgets everything written (writes off again).
     */
    public function reset(): void
    {
        $this->state = [];
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * A copy of the state, to put back when a write is dropped silently.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function restore(array $state): void
    {
        $this->state = $state;
        $this->save();
    }
}
