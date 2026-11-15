<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The signed part of the licence server's last "valid" answer, exactly as it was answered: the end
 * date and the time of the check as text, the nonce of the request and the Ed25519 signature
 * (Connectors\Licence\LicenceSignature). The site keeps it with its record and checks it again
 * every time it decides where the licence stands, so a record written by hand in the database
 * (status "valid", a recent date) never makes a licence valid.
 */
final class LicenceProof
{
    private const MAX = ['expires_at' => 40, 'checked_at' => 40, 'nonce' => 128, 'signature' => 200];

    public function __construct(
        public readonly string $expiresAt,
        public readonly string $checkedAt,
        public readonly string $nonce,
        public readonly string $signature,
    ) {
    }

    /**
     * @return array{expires_at: string, checked_at: string, nonce: string, signature: string}
     */
    public function toArray(): array
    {
        return ['expires_at' => $this->expiresAt, 'checked_at' => $this->checkedAt, 'nonce' => $this->nonce, 'signature' => $this->signature];
    }

    /**
     * The proof stored by toArray(); null for anything else.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        $fields = [];
        foreach (self::MAX as $name => $max) {
            $value = $data[$name] ?? null;
            if (!is_string($value) || strlen($value) > $max) {
                return null;
            }
            $fields[] = $value;
        }

        return new self(...$fields);
    }

    /**
     * The time of the check as signed, in UTC; null when it is not an ISO 8601 instant.
     */
    public function checkedInstant(): ?DateTimeImmutable
    {
        return self::instant($this->checkedAt);
    }

    /**
     * The end of the licence as signed, in UTC; null when it has none (or it is not an instant).
     */
    public function expiresInstant(): ?DateTimeImmutable
    {
        return $this->expiresAt === '' ? null : self::instant($this->expiresAt);
    }

    /**
     * An instant in ISO 8601 with a zone (Z or an offset), in UTC; null for anything else.
     */
    public static function instant(mixed $text): ?DateTimeImmutable
    {
        if (!is_string($text) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $text) !== 1) {
            return null;
        }
        $instant = DateTimeImmutable::createFromFormat(str_contains($text, '.') ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP', $text);
        if ($instant === false) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
