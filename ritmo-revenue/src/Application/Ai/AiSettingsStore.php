<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

use InvalidArgumentException;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Infrastructure\Secrets\SecretUnavailable;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use SensitiveParameter;

/**
 * Where the assistant's settings and key live: the settings in the plugin's options, the key in
 * the sodium vault (write-only: the screens only ever say whether one is saved and when).
 */
final class AiSettingsStore
{
    public const SETTINGS_KEY = 'ai_settings';

    public const SECRET = 'anthropic_api_key';

    private const KEY_MAX = 400;

    public function __construct(private readonly KeyValueStore $store, private readonly SodiumVault $vault)
    {
    }

    /**
     * The saved settings; settings that no longer read (an older version) give the defaults,
     * which keep the assistant off.
     */
    public function settings(): AiSettings
    {
        $stored = $this->store->get(self::SETTINGS_KEY);
        if (!is_array($stored)) {
            return AiSettings::defaults();
        }
        try {
            return AiSettings::fromArray($stored);
        } catch (InvalidConfig) {
            return AiSettings::defaults();
        }
    }

    public function save(AiSettings $settings): void
    {
        $this->store->set(self::SETTINGS_KEY, $settings->toArray());
    }

    public function hasKey(): bool
    {
        return $this->vault->has(self::SECRET);
    }

    /**
     * The key in clear, for the connector only; null when none can be read.
     */
    public function key(): ?string
    {
        if (!$this->hasKey()) {
            return null;
        }
        try {
            return $this->vault->reveal(self::SECRET);
        } catch (SecretUnavailable) {
            return null;
        }
    }

    /**
     * @throws InvalidArgumentException for a key with spaces or control characters, or too long
     * @throws SecretUnavailable        while the vault cannot store secrets
     */
    public function putKey(#[SensitiveParameter] string $key): void
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > self::KEY_MAX || preg_match('/[\x00-\x20\x7f]/', $key) === 1) {
            throw new InvalidArgumentException('A Claude API key cannot be empty or contain spaces or control characters.');
        }
        $this->vault->put(self::SECRET, $key);
    }

    public function forgetKey(): void
    {
        $this->vault->forget(self::SECRET);
    }

    public function vaultProblem(): ?string
    {
        return $this->vault->problem();
    }
}
