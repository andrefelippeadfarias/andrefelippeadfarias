<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;

/**
 * The settings of the optional assistant. Off by default: the plugin works without it. The key is
 * not here (it lives in the secrets vault and is never shown).
 *
 * - model: one of AiModel::CHOICES; once the connection was tested, modelAvailable() says
 *   whether the key may use it ($availableModels, empty while unknown);
 * - effort: sent on every request;
 * - fallback: the server-side fallback of a refused request (on by default);
 * - monthlyCapUsd: the estimated spend of a calendar month (site time) that switches the
 *   assistant off until the next month;
 * - timeoutSeconds: the wait for one answer.
 */
final class AiSettings
{
    public const CAP_MIN = 1.0;
    public const CAP_MAX = 10000.0;
    public const CAP_DEFAULT = 20.0;
    public const TIMEOUT_MIN = 15;
    public const TIMEOUT_MAX = 300;
    public const TIMEOUT_DEFAULT = 120;

    /**
     * @param list<string> $availableModels the models the key may use, from the last connection test
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $model = AiModel::DEFAULT,
        public readonly AiEffort $effort = AiEffort::DEFAULT,
        public readonly bool $fallback = true,
        public readonly float $monthlyCapUsd = self::CAP_DEFAULT,
        public readonly int $timeoutSeconds = self::TIMEOUT_DEFAULT,
        public readonly ?PriceTable $prices = null,
        public readonly array $availableModels = [],
    ) {
        $errors = self::problems($model, $monthlyCapUsd, $timeoutSeconds);
        if ($errors !== []) {
            throw new InvalidConfig('assistant settings', $errors);
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * The settings as stored; anything missing takes its default.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidConfig naming every field that does not read
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $effort = AiEffort::tryFrom((string) ($data['effort'] ?? AiEffort::DEFAULT->value));
        if ($effort === null) {
            $errors[] = new ValidationError('effort', ValidationError::CHOICE, 'choose low, medium or high');
        }
        try {
            $prices = PriceTable::fromArray(is_array($data['prices'] ?? null) ? $data['prices'] : []);
        } catch (\InvalidArgumentException $e) {
            $errors[] = new ValidationError('prices', ValidationError::FORMAT, $e->getMessage());
            $prices = PriceTable::defaults();
        }
        $cap = $data['monthly_cap_usd'] ?? self::CAP_DEFAULT;
        $timeout = $data['timeout_seconds'] ?? self::TIMEOUT_DEFAULT;
        if (!is_int($cap) && !is_float($cap)) {
            $errors[] = new ValidationError('monthly_cap_usd', ValidationError::TYPE, 'a number of dollars');
            $cap = self::CAP_DEFAULT;
        }
        if (!is_int($timeout)) {
            $errors[] = new ValidationError('timeout_seconds', ValidationError::TYPE, 'a whole number of seconds');
            $timeout = self::TIMEOUT_DEFAULT;
        }
        $available = array_values(array_filter(is_array($data['available_models'] ?? null) ? $data['available_models'] : [], static fn (mixed $m): bool => is_string($m) && AiModel::isWellFormed($m)));
        $model = is_string($data['model'] ?? null) ? $data['model'] : AiModel::DEFAULT;
        $errors = [...$errors, ...self::problems($model, (float) $cap, $timeout)];
        if ($errors !== []) {
            throw new InvalidConfig('assistant settings', $errors);
        }

        return new self(($data['enabled'] ?? false) === true, $model, $effort ?? AiEffort::DEFAULT, ($data['fallback'] ?? true) !== false, (float) $cap, $timeout, $prices, $available);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'model' => $this->model,
            'effort' => $this->effort->value,
            'fallback' => $this->fallback,
            'monthly_cap_usd' => $this->monthlyCapUsd,
            'timeout_seconds' => $this->timeoutSeconds,
            'prices' => $this->priceTable()->toArray(),
            'available_models' => $this->availableModels,
        ];
    }

    /**
     * False when the last connection test listed the models of the key and this one is not among
     * them; true while the list is unknown.
     */
    public function modelAvailable(): bool
    {
        return $this->availableModels === [] || in_array($this->model, $this->availableModels, true);
    }

    public function priceTable(): PriceTable
    {
        return $this->prices ?? PriceTable::defaults();
    }

    /**
     * A copy with other values (the screen changes a few fields at a time).
     *
     * @param array<string, mixed> $changes keys of toArray()
     *
     * @throws InvalidConfig
     */
    public function with(array $changes): self
    {
        return self::fromArray($changes + $this->toArray());
    }

    /**
     * @return list<ValidationError>
     */
    private static function problems(string $model, float $cap, int $timeout): array
    {
        $errors = [];
        if (!AiModel::isChoice($model)) {
            $errors[] = new ValidationError('model', ValidationError::CHOICE, 'choose one of the models offered');
        }
        if (!is_finite($cap) || $cap < self::CAP_MIN || $cap > self::CAP_MAX) {
            $errors[] = new ValidationError('monthly_cap_usd', ValidationError::RANGE, sprintf('from %d to %d dollars', (int) self::CAP_MIN, (int) self::CAP_MAX));
        }
        if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
            $errors[] = new ValidationError('timeout_seconds', ValidationError::RANGE, sprintf('from %d to %d seconds', self::TIMEOUT_MIN, self::TIMEOUT_MAX));
        }

        return $errors;
    }
}
