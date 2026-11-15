<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

use InvalidArgumentException;
use SodiumException;

/**
 * The Ed25519 public keys an update must be signed with: the key embedded in the plugin, or the
 * keys of the constant RITMO_UPDATE_PUBLIC_KEY (base64, separated by commas or spaces, so a key
 * can be rotated by publishing with the new key while the old one is still trusted).
 */
final class TrustedKeys
{
    public const KEY_BYTES = 32;

    public const SIGNATURE_BYTES = 64;

    /**
     * @param list<string> $keys raw 32-byte public keys
     */
    private function __construct(private readonly array $keys)
    {
    }

    /**
     * Keys from text; an empty text gives no key (updates off).
     *
     * @throws InvalidArgumentException when a part is not a base64 Ed25519 public key
     */
    public static function parse(string $text): self
    {
        $keys = [];
        foreach (preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $raw = base64_decode($part, true);
            if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
                throw new InvalidArgumentException('An update public key is 32 bytes in base64.');
            }
            $keys[] = $raw;
        }

        return new self(array_values(array_unique($keys)));
    }

    /**
     * @param list<string> $keys raw 32-byte public keys
     */
    public static function of(array $keys): self
    {
        foreach ($keys as $key) {
            if (strlen($key) !== self::KEY_BYTES) {
                throw new InvalidArgumentException('An update public key is 32 bytes.');
            }
        }

        return new self(array_values(array_unique($keys)));
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * True when one of the keys verifies the detached signature of $message.
     */
    public function verify(string $signature, string $message): bool
    {
        if (strlen($signature) !== self::SIGNATURE_BYTES) {
            return false;
        }
        foreach ($this->keys as $key) {
            try {
                if (sodium_crypto_sign_verify_detached($signature, $message, $key)) {
                    return true;
                }
            } catch (SodiumException) {
                continue;
            }
        }

        return false;
    }

    /**
     * Short ids of the keys (for the diagnostics), never the keys themselves.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (string $key): string => substr(hash('sha256', $key), 0, 12), $this->keys);
    }
}
