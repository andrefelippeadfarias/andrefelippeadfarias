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
 * key then lives outside the database). Otherwise it comes from a random seed created at
 * activation and kept in the KeyValueStore, bound to the site's salts (AUTH_KEY and
 * SECURE_AUTH_KEY of wp-config.php) when the site has them: a copy of the database alone then
 * decrypts nothing. Without either, the database holds everything needed to decrypt, and
 * protection() says so for the diagnostics. Each entry is encrypted under its own subkey, so an
 * entry copied over another one does not decrypt.
 *
 * A RITMO_SECRET_KEY that is too short does not break the site: the vault reports it through
 * problem() and refuses to store or reveal anything until it is fixed.
 *
 * For the interface there is only status(): configured or not, and when it was saved; nothing
 * of the secret is kept in clear. reveal() is for the connector that must send the secret; its
 * result is never shown, logged or returned by an endpoint.
 */
final class SodiumVault
{
    public const MIN_CONFIGURED_KEY_LENGTH = 32;

    /** The key is RITMO_SECRET_KEY, outside the database. */
    public const PROTECTION_CONFIGURED_KEY = 'configured_key';

    /** The key is the stored seed bound to the salts of wp-config.php. */
    public const PROTECTION_SITE_SALTS = 'site_salts';

    /** The key is the stored seed alone: a copy of the database decrypts the secrets. */
    public const PROTECTION_DATABASE_ONLY = 'database_only';

    /** RITMO_SECRET_KEY is invalid: nothing can be stored or revealed (see problem()). */
    public const PROTECTION_UNAVAILABLE = 'unavailable';

    private const STORE_KEY = 'vault';
    private const CONTEXT = 'ritmo-revenue/vault/v1';
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.:-]{0,63}$/';
    private const SALT_CONSTANTS = ['AUTH_KEY', 'SECURE_AUTH_KEY'];

    /** What an entry holds; anything else (such as characters of the secret kept by older versions) is dropped by upgrade(). */
    private const ENTRY_FIELDS = ['k', 'n', 'c', 'u'];
    private const DEFAULT_SALT = 'put your unique phrase here';

    /** @var Closure(): (string|null) */
    private readonly Closure $configuredKey;

    /** @var Closure(): (string|null) */
    private readonly Closure $siteSecret;

    private readonly ?string $problem;

    /**
     * @param string|null $configuredKey value of RITMO_SECRET_KEY; null when the site does not define it
     * @param string|null $siteSecret    secret of the site kept outside the database (its salts); null when it has none
     */
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
        #[SensitiveParameter]
        ?string $configuredKey = null,
        #[SensitiveParameter]
        ?string $siteSecret = null,
    ) {
        $this->problem = self::configuredKeyProblem($configuredKey);
        $usableKey = $this->problem === null ? $configuredKey : null;
        $usableSecret = $siteSecret !== null && strlen($siteSecret) >= self::MIN_CONFIGURED_KEY_LENGTH ? $siteSecret : null;

        // Held inside closures, not in plain properties, so that var_export() of the vault shows no key.
        $this->configuredKey = static fn (): ?string => $usableKey;
        $this->siteSecret = static fn (): ?string => $usableSecret;
    }

    /**
     * The vault of this site: uses the constant RITMO_SECRET_KEY when it is defined, else the
     * stored seed bound to the site's salts.
     */
    public static function forSite(KeyValueStore $store, Clock $clock): self
    {
        return new self($store, $clock, defined('RITMO_SECRET_KEY') ? (string) constant('RITMO_SECRET_KEY') : null, self::siteSalts());
    }

    /**
     * Why a value cannot be RITMO_SECRET_KEY, or null when it can (or is not set).
     */
    public static function configuredKeyProblem(#[SensitiveParameter] ?string $configuredKey): ?string
    {
        if ($configuredKey === null || strlen($configuredKey) >= self::MIN_CONFIGURED_KEY_LENGTH) {
            return null;
        }

        return sprintf('RITMO_SECRET_KEY must have at least %d characters. Saved keys cannot be used or changed until it is fixed in wp-config.php.', self::MIN_CONFIGURED_KEY_LENGTH);
    }

    /**
     * Why the vault cannot store or reveal secrets, or null when it can.
     */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * Where the encryption key lives: one of the PROTECTION_* constants.
     */
    public function protection(): string
    {
        return match (true) {
            $this->problem !== null => self::PROTECTION_UNAVAILABLE,
            ($this->configuredKey)() !== null => self::PROTECTION_CONFIGURED_KEY,
            ($this->siteSecret)() !== null => self::PROTECTION_SITE_SALTS,
            default => self::PROTECTION_DATABASE_ONLY,
        };
    }

    /**
     * Prepares the vault at activation: creates the random seed when the site has no
     * configured key and no seed yet, then moves the entries to the current key (upgrade()).
     * Does nothing while the vault has a problem. Safe to call again.
     */
    public function install(): void
    {
        if ($this->problem === null) {
            $this->writingKey();
            $this->upgrade();
        }
    }

    /**
     * Re-encrypts under the key the vault writes with every entry it can read under another one
     * (written before the seed was bound to the site's salts, or with the seed before
     * RITMO_SECRET_KEY was set), and drops what older versions kept next to an entry. Entries it
     * cannot read are left as they are. With RITMO_SECRET_KEY and every entry under it, the
     * stored seed is dropped.
     *
     * @return int entries rewritten
     */
    public function upgrade(): int
    {
        if ($this->problem !== null) {
            return 0;
        }
        $key = $this->writingKey();
        $state = $this->state();
        $readingKeys = $this->readingKeys($state, null);
        $current = $this->keyId($key);
        $moved = 0;
        $behind = 0;
        foreach ($state['entries'] as $name => $entry) {
            if (($entry['k'] ?? '') === $current && array_keys($entry) === self::ENTRY_FIELDS) {
                continue;
            }
            try {
                $secret = $this->decrypt((string) $name, $entry, $readingKeys);
            } catch (SecretUnavailable) {
                ++$behind;
                continue;
            }
            $state['entries'][$name] = $this->encrypt((string) $name, $secret, $key, $entry);
            ++$moved;
        }
        $dropSeed = ($this->configuredKey)() !== null && $behind === 0 && $state['seed'] !== null;
        if ($dropSeed) {
            $state['seed'] = null;
        }
        if ($moved > 0 || $dropSeed) {
            $this->store->set(self::STORE_KEY, $state);
        }

        return $moved;
    }

    /**
     * Stores a secret, replacing the one with the same name.
     *
     * @param string $name   what the secret is ("beds24.refresh_token"): lowercase letters, digits and "_.:-"
     * @param string $secret the secret, not empty
     */
    /**
     * @throws SecretUnavailable while the vault has a problem
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
     * @throws SecretUnavailable when it is not stored or cannot be decrypted, or while the vault has a problem
     */
    public function reveal(string $name): string
    {
        if ($this->problem !== null) {
            throw new SecretUnavailable($this->problem);
        }
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

        return new SecretStatus(true, DbValue::toInstant((string) ($entry['u'] ?? '')));
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
     * @throws SecretUnavailable when an entry cannot be decrypted with the keys at hand, or while the vault has a problem
     */
    public function rotate(#[SensitiveParameter] ?string $previousConfiguredKey = null): int
    {
        if ($this->problem !== null) {
            throw new SecretUnavailable($this->problem);
        }
        $state = $this->state();
        $readingKeys = $this->readingKeys($state, $previousConfiguredKey);

        $secrets = [];
        foreach ($state['entries'] as $name => $entry) {
            $secrets[(string) $name] = $this->decrypt((string) $name, $entry, $readingKeys);
        }

        $configuredKey = ($this->configuredKey)();
        $seed = $configuredKey === null ? $this->newSeed() : null;
        $key = $configuredKey !== null ? $this->derive($configuredKey) : $this->seedKey((string) $this->seedMaterial((string) $seed), ($this->siteSecret)());
        $entries = [];
        foreach ($secrets as $name => $secret) {
            $entries[$name] = $this->encrypt($name, $secret, $key, $state['entries'][$name]);
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
        return ['uses_configured_key' => ($this->configuredKey)() !== null, 'uses_site_salts' => ($this->siteSecret)() !== null];
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
     * @return array{seed: string|null, entries: array<string, array<string, string>>}
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
     *
     * @throws SecretUnavailable while the vault has a problem
     */
    private function writingKey(): string
    {
        if ($this->problem !== null) {
            throw new SecretUnavailable($this->problem);
        }
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

        return $this->seedKey($material, ($this->siteSecret)());
    }

    /**
     * The key of a seed: bound to the site's secret when there is one, so that the stored seed
     * alone opens nothing.
     */
    private function seedKey(#[SensitiveParameter] string $material, #[SensitiveParameter] ?string $siteSecret): string
    {
        return $this->derive($siteSecret === null ? $material : $material . "\0site\0" . $siteSecret);
    }

    /**
     * The salts of wp-config.php that never live in the database, or null when the site does not
     * define them (or kept the placeholder of wp-config-sample.php).
     */
    private static function siteSalts(): ?string
    {
        $parts = [];
        foreach (self::SALT_CONSTANTS as $constant) {
            $value = defined($constant) ? (string) constant($constant) : '';
            if ($value === '' || $value === self::DEFAULT_SALT) {
                return null;
            }
            $parts[] = $value;
        }

        return implode("\0", $parts);
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
        $candidates = [];
        foreach ([($this->configuredKey)(), $previousConfiguredKey] as $configured) {
            if ($configured !== null && $configured !== '') {
                $candidates[] = $this->derive($configured);
            }
        }
        $seed = $state['seed'] === null ? null : $this->seedMaterial($state['seed']);
        if ($seed !== null) {
            $siteSecret = ($this->siteSecret)();
            if ($siteSecret !== null) {
                $candidates[] = $this->seedKey($seed, $siteSecret);
            }
            // Entries written before the seed was bound to the salts, until upgrade() moves them.
            $candidates[] = $this->seedKey($seed, null);
        }

        $keys = [];
        foreach ($candidates as $key) {
            $keys[$this->keyId($key)] = $key;
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
     * @param array<string, mixed>|null $previous the entry this one replaces when the secret did not change: its save date is kept
     *
     * @return array{k: string, n: string, c: string, u: string}
     */
    private function encrypt(string $name, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $key, ?array $previous = null): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $saved = is_string($previous['u'] ?? null) && $previous['u'] !== '' ? $previous['u'] : DbValue::instant($this->clock->now());

        return [
            'k' => $this->keyId($key),
            'n' => base64_encode($nonce),
            'c' => base64_encode(sodium_crypto_secretbox($secret, $nonce, $this->entryKey($name, $key))),
            'u' => $saved,
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
