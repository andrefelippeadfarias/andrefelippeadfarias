<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use DateTimeZone;
use InvalidArgumentException;

/**
 * A property (one hotel or inn) managed by the site.
 */
final class Property
{
    /**
     * @param string            $id             stable key chosen at setup, unique per site; rule maps
     *                                          (price factors) reference properties by it
     * @param string            $timezone       IANA identifier; "today" of the property is read in it
     * @param string            $currency       ISO 4217 code, three capital letters
     * @param array<int, float> $occupancyGoals own occupancy goals: window in days => percent sold;
     *                                          empty means "use the goals of the rule set"
     * @param string            $tier           slug of the target-percentile table the property uses
     *
     * @throws InvalidArgumentException on any invalid value
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly PropertyMode $mode = PropertyMode::Shadow,
        public readonly array $occupancyGoals = [],
        public readonly string $tier = 'standard',
    ) {
        if (!Identifier::isCode($id)) {
            throw new InvalidArgumentException(sprintf('Invalid property id "%s".', $id));
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('A property needs a name.');
        }
        try {
            new DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf('Invalid timezone "%s".', $timezone), 0, $e);
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid currency "%s": expected an ISO 4217 code.', $currency));
        }
        if (!Identifier::isSlug($tier)) {
            throw new InvalidArgumentException(sprintf('Invalid tier "%s".', $tier));
        }
        foreach ($occupancyGoals as $window => $percent) {
            if (!is_int($window) || $window < 1 || !(is_int($percent) || is_float($percent)) || $percent < 0 || $percent > 100) {
                throw new InvalidArgumentException('Occupancy goals must map a window in days to a percentage from 0 to 100.');
            }
        }
    }

    /**
     * Builds a property from its preset/storage form (snake_case keys).
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when a required key is missing or a value is invalid
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'timezone', 'currency'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new InvalidArgumentException(sprintf('Property is missing the text field "%s".', $key));
            }
        }

        $modeValue = $data['mode'] ?? PropertyMode::Shadow->value;
        $mode = is_string($modeValue) ? PropertyMode::tryFrom($modeValue) : null;
        if ($mode === null) {
            throw new InvalidArgumentException('Invalid property mode: expected "shadow", "approval" or "auto".');
        }

        $goals = [];
        $rawGoals = $data['occupancy_goals'] ?? [];
        if (!is_array($rawGoals)) {
            throw new InvalidArgumentException('Property "occupancy_goals" must be a map.');
        }
        foreach ($rawGoals as $window => $percent) {
            if (!(is_int($window) || ctype_digit((string) $window)) || !(is_int($percent) || is_float($percent))) {
                throw new InvalidArgumentException('Occupancy goals must map a window in days to a percentage from 0 to 100.');
            }
            $goals[(int) $window] = (float) $percent;
        }

        $tier = $data['tier'] ?? 'standard';
        if (!is_string($tier)) {
            throw new InvalidArgumentException('Property "tier" must be text.');
        }

        return new self($data['id'], $data['name'], $data['timezone'], $data['currency'], $mode, $goals, $tier);
    }

    /**
     * @return array{id: string, name: string, timezone: string, currency: string, mode: string, tier: string, occupancy_goals: array<int, float>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'mode' => $this->mode->value,
            'tier' => $this->tier,
            'occupancy_goals' => $this->occupancyGoals,
        ];
    }
}
