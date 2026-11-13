<?php

declare(strict_types=1);

namespace RitmoFakeApis;

/**
 * The write side of the PriceLabs Customer API, with its real semantics (apis-verificadas.md):
 * - POST /listings/{id}/overrides merges the fields of each date into the stored rule; one invalid
 *   entry refuses the whole call (400, nothing stored); a listing not priced yet answers the 400
 *   "Pricing data for this date isn't available yet"; the answer echoes the stored rules;
 * - DELETE /listings/{id}/overrides removes every field of the dates (204);
 * - POST /refresh_listing: 3 per listing in 24 h and 10 per minute on the account, failures
 *   counting; beyond that, 429;
 * - POST /listings changes min, base and max.
 */
final class PriceLabsWrites
{
    private const FIELDS = ['date', 'price', 'price_type', 'min_price', 'min_price_type', 'max_price', 'max_price_type', 'min_stay', 'check_in_check_out_enabled', 'check_in', 'check_out', 'reason', 'currency'];

    public function __construct(private readonly Scenario $scenario, private readonly WriteWorld $world)
    {
    }

    public function handle(string $method, string $path, string $body): ?Reply
    {
        $data = json_decode($body, true);
        if (preg_match('#^/listings/([^/]+)/overrides$#', $path, $match) === 1 && ($method === 'POST' || $method === 'DELETE')) {
            $this->world->logWrite($method, '/pricelabs/v1' . $path, $data);
            $listing = rawurldecode($match[1]);

            return $method === 'POST' ? $this->write($listing, $data) : $this->delete($listing, $data);
        }
        if ($method === 'POST' && $path === '/refresh_listing') {
            $this->world->logWrite($method, '/pricelabs/v1' . $path, $data);

            return $this->refresh($data);
        }
        if ($method === 'POST' && $path === '/listings') {
            $this->world->logWrite($method, '/pricelabs/v1' . $path, $data);

            return $this->listings($data);
        }

        return null;
    }

    private function write(string $listing, mixed $data): Reply
    {
        if (!$this->known($listing, $data) || !is_array($data['overrides'] ?? null) || $data['overrides'] === []) {
            return self::error(400, 'LISTING_NOT_PRESENT');
        }
        if ($this->world->isNotReady($listing)) {
            return Reply::json(400, ['error' => "Pricing data for this date isn't available yet"]);
        }
        foreach ($data['overrides'] as $rule) {
            if (!self::valid($rule)) {
                return self::error(400, 'INVALID_OVERRIDE');
            }
        }
        $stored = [];
        foreach ($data['overrides'] as $rule) {
            $stored[] = $this->world->mergeRule($listing, $rule);
        }
        $this->world->save();

        return Reply::json(200, ['overrides' => $stored]);
    }

    private function delete(string $listing, mixed $data): Reply
    {
        if (!$this->known($listing, $data) || !is_array($data['overrides'] ?? null)) {
            return self::error(400, 'LISTING_NOT_PRESENT');
        }
        foreach ($data['overrides'] as $rule) {
            $this->world->deleteRule($listing, (string) ($rule['date'] ?? ''));
        }
        $this->world->save();

        return new Reply(204);
    }

    private function refresh(mixed $data): Reply
    {
        $listing = is_array($data) ? (string) ($data['listing_id'] ?? '') : '';
        if (!$this->known($listing, $data)) {
            return self::error(400, 'LISTING_NOT_PRESENT');
        }
        $allowed = $this->world->refresh($listing, time());
        $this->world->save();

        return $allowed ? Reply::json(200, ['success' => true]) : self::error(429, 'RATE_LIMITED');
    }

    private function listings(mixed $data): Reply
    {
        foreach (is_array($data) ? ($data['listings'] ?? []) : [] as $listing) {
            if (!$this->known((string) ($listing['id'] ?? ''), $listing)) {
                return self::error(400, 'LISTING_NOT_PRESENT');
            }
            $this->world->updateListing((string) $listing['id'], array_intersect_key($listing, array_flip(['min', 'base', 'max'])));
        }
        $this->world->save();

        return Reply::json(200, ['success' => true]);
    }

    private function known(string $listing, mixed $data): bool
    {
        return is_array($data) && ($data['pms'] ?? '') === $this->scenario->priceLabs()['pms'] && $this->scenario->roomOfListing($listing) !== null;
    }

    private static function valid(mixed $rule): bool
    {
        if (!is_array($rule) || array_diff(array_keys($rule), self::FIELDS) !== [] || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($rule['date'] ?? '')) !== 1) {
            return false;
        }
        if (isset($rule['price']) && !isset($rule['price_type'])) {
            return false;
        }
        foreach (['min_price', 'max_price'] as $bound) {
            if (isset($rule[$bound]) && ($rule[$bound . '_type'] ?? '') === 'percent_base' && ((int) $rule[$bound] < -75 || (int) $rule[$bound] > 1000)) {
                return false;
            }
        }

        return !isset($rule['min_stay']) || ((int) $rule['min_stay'] >= 1 && (int) $rule['min_stay'] <= 30);
    }

    private static function error(int $status, string $code): Reply
    {
        return Reply::json($status, ['error' => ['code' => $code, 'message' => 'Simulated PriceLabs error.']]);
    }
}
