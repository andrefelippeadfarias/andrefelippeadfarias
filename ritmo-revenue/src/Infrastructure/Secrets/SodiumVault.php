<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Secrets;

use Closure;
use InvalidArgumentException;
use LogicException;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\DbValue;
use SensitiveParameter;
use SodiumException;

/**
 * The encrypted store of API keys and tokens (sodium_crypto_secretbox).
 *
 * The encryption key comes from the constant RITMO_SECRET_KEY when the site defines it (the
 * key then lives outside the database), otherwise from a random seed created at activation
 * and kept in the KeyValueStore. Each entry is encrypted under its own subkey, so an entry
 * copied over another one does not decrypt.
 *
 * For the interface there is only status(): configured or not, and the last four characters.
 * reveal() is for the connector that must send the secret; its result is never shown, logged
 * or returned by an endpoint.
 */
final class SodiumVault
{
    public const MIN_CONFIGURED_KEY_LENGTH = 32;

    private const STORE_KEY = 'vault';
    private const CONTEXT = 'ritmo-revenue/vault/v1';
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.:-]{0,63}$/';
    private const SHOWN_CHARACTERS = 4;
    private const MIN_LENGTH_TO_SHOW = 12;

    /** @var Closure(): (string|null) */
    private readonly Closure $configuredKey;

    /**
     * @param string|null $configuredKey value of RITMO_SECRET_KEY; null when the site does not define it
     *
     * @throws InvalidArgumentException when the configured key is shorter than 32 characters
     */
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
        #[SensitiveParameter]
        ?string $configuredKey = null,
    ) {
        if ($configuredKey !== null && strlen($configuredKey) < self::MIN_CONFIGURED_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf('RITMO_SECRET_KEY must have at least %d characters.', self::MIN_CONFIGURED_KEY_LENGTH));
        }

        // Held inside a closure, not in a plain property, so that var_export() of the vault shows no key.
        $this->configuredKey = static fn (): ?string => $configuredKey;
    }

    /**
     * The vault of this site: uses the constant RITMO_SECRET_KEY when it is defined.
     */
    public static function forSite(KeyValueStore $store, Clock $clock): self
    {
        return new self($store, $clock, defined('RITMO_SECRET_KEY') ? (string) constant('RITMO_SECRET_KEY') : null);
    }

    /**
     * Prepares the vault at activation: creates the random seed when the site has no
     * configured key and no seed yet. Safe to call again.
     */
    public function install(): void
    {
        $this->writingKey();
    }

    /**
     * Stores a secret, replacing the one with the same name.
     *
     * @param string $name   what the secret is ("beds24.refresh_token"): lowercase letters, digits and "_.:-"
     * @param string $secret the secret, not empty
     */
    public function put(string $name, #[SensitiveParameter] string $secret): void
    {
        $this->assertName($name);
        if ($secret === '') {
            throw new InvalidArgumentException('A secret cannot be empty; use forget() to remove it.');
        }

        // The key first: it stores a new seed when the vault has none yet.
        $key = $this->writingKey();
        $state = $this->state();
        $state['entries'][$name] = $this->encrypt($name, $secret, $key);
        $this->store->set(self::STORE_KEY, $state);
    }

    /**
     * The secret in clear, for the code that sends it to its owner system.
     *
     * @throws SecretUnavailable when it is not stored or cannot be decrypted
     */
    public function reveal(string $name): string
    {
        $state = $this->state();
        if (!isset($state['entries'][$name])) {
            throw new SecretUnavailable(sprintf('The secret "%s" is not configured.', $name));
        }

        return $this->decrypt($name, $state['entries'][$name], $this->readingKeys($state, null));
    }

    public function has(string $name): bool
    {
        return isset($this->state()['entries'][$name]);
    }

    /**
     * What the interface may show about a secret.
     */
    public function status(string $name): SecretStatus
    {
        $entry = $this->state()['entries'][$name] ?? null;
        if ($entry === null) {
            return new SecretStatus(false);
        }

        return new SecretStatus(true, (string) $entry['l'], DbValue::toInstant((string) $entry['u']));
    }

    public function forget(string $name): void
    {
        $state = $this->state();
        if (isset($state['entries'][$name])) {
            unset($state['entries'][$name]);
            $this->store->set(self::STORE_KEY, $state);
        }
    }

    /**
     * @return list<string> names of the stored secrets
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->state()['entries']));
    }

    /**
     * Re-encrypts every secret under a new key, in one write: either all entries move to the
     * new key or nothing changes.
     *
     * - Without a configured key, a fresh random seed replaces the stored one.
     * - With a configured key, everything moves under it and the stored seed is dropped. After
     *   changing RITMO_SECRET_KEY, pass the old value so the entries can be read.
     *
     * @param string|null $previousConfiguredKey the RITMO_SECRET_KEY the entries were written with, when it changed
     *
     * @return int entries re-encrypted
     *
     * @throws SecretUnavailable when an entry cannot be decrypted with the keys at hand
     */
    public function rotate(#[SensitiveParameter] ?string $previousConfiguredKey = null): int
    {
        $state = $this->state();
        $readingKeys = $this->readingKeys($state, $previousConfiguredKey);

        $secrets = [];
        foreach ($state['entries'] as $name => $entry) {
            $secrets[(string) $name] = $this->decrypt((string) $name, $entry, $readingKeys);
        }

        $configuredKey = ($this->configuredKey)();
        $seed = $configuredKey === null ? $this->newSeed() : null;
        $key = $this->derive($configuredKey ?? (string) $this->seedMaterial((string) $seed));
        $entries = [];
        foreach ($secrets as $name => $secret) {
            $entries[$name] = $this->encrypt($name, $secret, $key);
        }
        $this->store->set(self::STORE_KEY, ['seed' => $seed, 'entries' => $entries]);

        return count($entries);
    }

    /**
     * Keeps the configured key out of var_dump() and error reports.
     *
     * @return array<string, bool>
     */
    public function __debugInfo(): array
    {
        return ['uses_configured_key' => ($this->configuredKey)() !== null];
    }

    /**
     * The vault is never serialized: its key would travel with it.
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('The secrets vault cannot be serialized.');
    }

    /**
     * @return array{seed: string|null, entries: array<string, array{k: string, n: string, c: string, l: string, u: string}>}
     */
    private function state(): array
    {
        $stored = $this->store->get(self::STORE_KEY);
        if (!is_array($stored)) {
            return ['seed' => null, 'entries' => []];
        }

        return [
            'seed' => is_string($stored['seed'] ?? null) ? $stored['seed'] : null,
            'entries' => is_array($stored['entries'] ?? null) ? $stored['entries'] : [],
        ];
    }

    /**
     * The key new entries are written with; creates and stores the seed when one is needed.
     */
    private function writingKey(): string
    {
        $configuredKey = ($this->configuredKey)();
        if ($configuredKey !== null) {
            return $this->derive($configuredKey);
        }

        $state = $this->state();
        $material = $state['seed'] === null ? null : $this->seedMaterial($state['seed']);
        if ($material === null) {
            // No seed yet, or a damaged one: start a new seed. Entries written with a lost
            // seed stay unreadable and say so when they are revealed.
            $state['seed'] = $this->newSeed();
            $this->store->set(self::STORE_KEY, $state);
            $material = (string) $this->seedMaterial($state['seed']);
        }

        return $this->derive($material);
    }

    private function newSeed(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * The random bytes of a stored seed; null when the stored value is not a seed the vault
     * wrote (no key is ever derived from such a value).
     */
    private function seedMaterial(string $seed): ?string
    {
        $material = base64_decode($seed, true);

        return is_string($material) && strlen($material) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $material : null;
    }

    /**
     * Every key an entry may have been written with, by key id.
     *
     * @param array{seed: string|null, entries: array<string, mixed>} $state
     *
     * @return array<string, string>
     */
    private function readingKeys(array $state, #[SensitiveParameter] ?string $previousConfiguredKey): array
    {
        $materials = [($this->configuredKey)(), $previousConfiguredKey, $state['seed'] === null ? null : $this->seedMaterial($state['seed'])];

        $keys = [];
        foreach ($materials as $material) {
            if ($material !== null && $material !== '') {
                $key = $this->derive($material);
                $keys[$this->keyId($key)] = $key;
            }
        }

        return $keys;
    }

    private function derive(#[SensitiveParameter] string $material): string
    {
        return sodium_crypto_generichash(self::CONTEXT . "\0" . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private function keyId(#[SensitiveParameter] string $key): string
    {
        return substr(sodium_bin2hex(sodium_crypto_generichash('key-id', $key)), 0, 16);
    }

    private function entryKey(string $name, #[SensitiveParameter] string $key): string
    {
        return sodium_crypto_generichash('entry:' . $name, $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * @return array{k: string, n: string, c: string, l: string, u: string}
     */
    private function encrypt(string $name, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $key): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return [
            'k' => $this->keyId($key),
            'n' => base64_encode($nonce),
            'c' => base64_encode(sodium_crypto_secretbox($secret, $nonce, $this->entryKey($name, $key))),
            'l' => strlen($secret) >= self::MIN_LENGTH_TO_SHOW ? substr($secret, -self::SHOWN_CHARACTERS) : '',
            'u' => DbValue::instant($this->clock->now()),
        ];
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, string> $keys  by key id
     *
     * @throws SecretUnavailable
     */
    private function decrypt(string $name, array $entry, #[SensitiveParameter] array $keys): string
    {
        $key = $keys[(string) ($entry['k'] ?? '')] ?? null;
        if ($key === null) {
            throw new SecretUnavailable(sprintf('The secret "%s" was encrypted with a key that is no longer available. Enter it again.', $name));
        }

        $nonce = base64_decode((string) ($entry['n'] ?? ''), true);
        $cipher = base64_decode((string) ($entry['c'] ?? ''), true);
        try {
            $secret = is_string($nonce) && is_string($cipher) ? sodium_crypto_secretbox_open($cipher, $nonce, $this->entryKey($name, $key)) : false;
        } catch (SodiumException) {
            $secret = false;
        }
        if ($secret === false) {
            throw new SecretUnavailable(sprintf('The secret "%s" is damaged and cannot be decrypted. Enter it again.', $name));
        }

        return $secret;
    }

    private function assertName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid secret name.', $name));
        }
    }
}
