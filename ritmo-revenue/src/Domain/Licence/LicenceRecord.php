<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * What the site remembers about its licence: the answers of the provider, never the key (only its
 * fingerprint). Immutable; withAnswer() gives the record after a new answer.
 *
 * An answer "unreachable" changes nothing but the time and outcome of the last attempt: the last
 * real answer is kept, so a provider that is down never takes a valid licence away before the
 * grace period ends (LicenceEvaluator).
 */
final class LicenceRecord
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    public const UNREACHABLE = 'unreachable';

    /** Id of the provider that answers test keys; its answers count only in development mode. */
    public const TEST_PROVIDER = 'stub';

    public const MESSAGE_MAX = 200;

    private const STATUSES = [self::VALID, self::EXPIRED, self::INVALID];

    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /**
     * @param string                 $keyFingerprint    LicenceKey::fingerprint() of the saved key; '' without a key
     * @param string                 $providerId        provider of the last real answer; '' before any
     * @param string                 $status            last real answer: valid, expired or invalid; '' before any
     * @param DateTimeImmutable|null $expiresAt         end of the licence as last answered (UTC)
     * @param DateTimeImmutable|null $lastAnswerAt      last real answer
     * @param DateTimeImmutable|null $lastAttemptAt     last attempt, answered or not
     * @param string                 $lastAttemptStatus outcome of the last attempt (a status or unreachable); '' before any
     * @param DateTimeImmutable|null $lastValidAt       last answer "valid"
     * @param DateTimeImmutable|null $problemSince      first answer "expired" or "invalid" after the last "valid"
     * @param string                 $message           the provider's last message, plain text
     * @param LicenceProof|null      $proof             the signed part of the last answer "valid" of the licence server
     */
    public function __construct(
        public readonly string $keyFingerprint = '',
        public readonly string $providerId = '',
        public readonly string $status = '',
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?DateTimeImmutable $lastAnswerAt = null,
        public readonly ?DateTimeImmutable $lastAttemptAt = null,
        public readonly string $lastAttemptStatus = '',
        public readonly ?DateTimeImmutable $lastValidAt = null,
        public readonly ?DateTimeImmutable $problemSince = null,
        public readonly string $message = '',
        public readonly ?LicenceProof $proof = null,
    ) {
        if ($keyFingerprint !== '' && preg_match('/^[0-9a-f]{16}$/', $keyFingerprint) !== 1) {
            throw new InvalidArgumentException('A licence key fingerprint is 16 hexadecimal characters.');
        }
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown licence status "%s".', $status));
        }
        if ($lastAttemptStatus !== '' && !in_array($lastAttemptStatus, [...self::STATUSES, self::UNREACHABLE], true)) {
            throw new InvalidArgumentException(sprintf('Unknown licence status "%s".', $lastAttemptStatus));
        }
    }

    /**
     * The record of a key just saved: nothing answered yet.
     */
    public static function forKey(LicenceKey $key): self
    {
        return new self($key->fingerprint());
    }

    public function hasKey(): bool
    {
        return $this->keyFingerprint !== '';
    }

    public function isFor(LicenceKey $key): bool
    {
        return $this->keyFingerprint !== '' && hash_equals($this->keyFingerprint, $key->fingerprint());
    }

    /**
     * The record after an answer of the provider. An answer older than the last attempt is ignored.
     *
     * @param string $status valid, expired, invalid or unreachable
     *
     * @throws InvalidArgumentException on an unknown status
     */
    public function withAnswer(string $providerId, string $status, ?DateTimeImmutable $expiresAt, DateTimeImmutable $at, string $message = '', ?LicenceProof $proof = null): self
    {
        if (!in_array($status, [...self::STATUSES, self::UNREACHABLE], true)) {
            throw new InvalidArgumentException(sprintf('Unknown licence status "%s".', $status));
        }
        $at = self::utc($at);
        if ($this->lastAttemptAt !== null && $at < $this->lastAttemptAt) {
            return $this;
        }
        if ($status === self::UNREACHABLE) {
            return new self($this->keyFingerprint, $this->providerId, $this->status, $this->expiresAt, $this->lastAnswerAt, $at, self::UNREACHABLE, $this->lastValidAt, $this->problemSince, $this->message, $this->proof);
        }
        $expiresAt = $expiresAt === null ? null : self::utc($expiresAt);
        if ($status === self::EXPIRED && $expiresAt === null) {
            $expiresAt = $this->expiresAt;
        }
        $message = self::cleanMessage($message);
        if ($status === self::VALID) {
            return new self($this->keyFingerprint, $providerId, $status, $expiresAt, $at, $at, $status, $at, null, $message, $proof);
        }

        return new self($this->keyFingerprint, $providerId, $status, $expiresAt, $at, $at, $status, $this->lastValidAt, $this->problemSince ?? $at, $message, $this->proof);
    }

    /**
     * The record as far as its last answer "valid" is proven by the signature of the licence
     * server: the last valid answer is never later than the signed time of the check, and while
     * the status is "valid" the end date is the signed one.
     */
    public function withSignedValid(DateTimeImmutable $checkedAt, ?DateTimeImmutable $expiresAt): self
    {
        if ($this->lastValidAt === null) {
            return $this;
        }
        $checkedAt = self::utc($checkedAt);
        $lastValidAt = $checkedAt < $this->lastValidAt ? $checkedAt : $this->lastValidAt;
        $end = $this->status === self::VALID ? ($expiresAt === null ? null : self::utc($expiresAt)) : $this->expiresAt;

        return new self($this->keyFingerprint, $this->providerId, $this->status, $end, $this->lastAnswerAt, $this->lastAttemptAt, $this->lastAttemptStatus, $lastValidAt, $this->problemSince, $this->message, $this->proof);
    }

    /**
     * The record with its answers "valid" left out (they cannot be proven): it grants nothing more
     * than a key never answered "valid".
     */
    public function withoutValid(): self
    {
        if ($this->lastValidAt === null && $this->status !== self::VALID) {
            return $this;
        }

        return new self($this->keyFingerprint, $this->providerId, $this->status === self::VALID ? '' : $this->status, $this->expiresAt, $this->lastAnswerAt, $this->lastAttemptAt, $this->lastAttemptStatus === self::VALID ? '' : $this->lastAttemptStatus, null, null, $this->message);
    }

    /**
     * @return array<string, string|array<string, string>|null>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->keyFingerprint,
            'provider' => $this->providerId,
            'status' => $this->status,
            'expires_at' => self::text($this->expiresAt),
            'last_answer_at' => self::text($this->lastAnswerAt),
            'last_attempt_at' => self::text($this->lastAttemptAt),
            'last_attempt_status' => $this->lastAttemptStatus,
            'last_valid_at' => self::text($this->lastValidAt),
            'problem_since' => self::text($this->problemSince),
            'message' => $this->message,
            'proof' => $this->proof?->toArray(),
        ];
    }

    /**
     * The record stored by toArray(). Anything that is not such a record (missing, damaged) is the
     * empty record: no key, nothing answered, so it never grants more than having no licence.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            return new self();
        }
        try {
            return new self(
                self::string($data, 'key'),
                self::string($data, 'provider'),
                self::string($data, 'status'),
                self::instant($data, 'expires_at'),
                self::instant($data, 'last_answer_at'),
                self::instant($data, 'last_attempt_at'),
                self::string($data, 'last_attempt_status'),
                self::instant($data, 'last_valid_at'),
                self::instant($data, 'problem_since'),
                self::cleanMessage(self::string($data, 'message')),
                LicenceProof::fromArray($data['proof'] ?? null),
            );
        } catch (InvalidArgumentException) {
            return new self();
        }
    }

    /**
     * A message of the provider as plain text: no control characters or markup, at most
     * MESSAGE_MAX characters.
     */
    public static function cleanMessage(string $message): string
    {
        $text = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags($message));
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if (preg_match('//u', $text) !== 1) {
            return '';
        }

        return mb_substr($text, 0, self::MESSAGE_MAX);
    }

    private static function utc(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    private static function text(?DateTimeImmutable $instant): ?string
    {
        return $instant?->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        if (!is_string($value)) {
            throw new InvalidArgumentException('A stored licence field is not text.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function instant(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match(self::INSTANT, $value) !== 1) {
            throw new InvalidArgumentException('A stored licence instant is not an ISO 8601 UTC instant.');
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($instant === false || $instant->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('A stored licence instant is not a real date.');
        }

        return $instant;
    }
}
