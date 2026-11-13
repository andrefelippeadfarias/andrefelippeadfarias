<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * An alert to raise. Its dedupe key is the code, the property and the scope (room and night,
 * booking, channel group, provider...): raising the same key again refreshes the alert instead of
 * repeating it. The context carries ids, numbers and codes, never guest data ({@see GuestDataGuard}).
 */
final class AlertDraft
{
    public const KEY_MAX = 190;

    public readonly AlertSeverity $severity;

    public readonly ?DateTimeImmutable $night;

    /**
     * @param list<string>         $scope      the parts of the dedupe key after the code and the property
     * @param string               $message    English sentence of the plugin, safe to show
     * @param array<string, mixed> $context    facts behind the alert
     * @param DateTimeImmutable|null $evidenceAt instant of the fact (booking change, failure); decides whether a resolved event alert comes back
     *
     * @throws InvalidArgumentException on an empty message or guest data in the context
     */
    public function __construct(
        public readonly AlertCode $code,
        public readonly string $propertyId,
        public readonly array $scope,
        public readonly string $message,
        public readonly array $context = [],
        public readonly string $roomCode = '',
        ?DateTimeImmutable $night = null,
        public readonly ?DateTimeImmutable $evidenceAt = null,
        ?AlertSeverity $severity = null,
    ) {
        if (trim($message) === '') {
            throw new InvalidArgumentException('An alert needs its message.');
        }
        GuestDataGuard::check($context);
        $this->severity = $severity ?? $code->severity();
        $this->night = $night === null ? null : Days::normalize($night);
    }

    /**
     * An instant as it is written in an alert's context: UTC, to the second.
     */
    public static function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }

    public function dedupeKey(): string
    {
        return self::keyFor($this->code, $this->propertyId, $this->scope);
    }

    /**
     * The dedupe key of an alert: code, property and scope joined by ":" (long keys end in a hash).
     *
     * @param list<string> $scope
     */
    public static function keyFor(AlertCode $code, string $propertyId, array $scope = []): string
    {
        $key = implode(':', [$code->value, $propertyId, ...$scope]);

        return strlen($key) <= self::KEY_MAX ? $key : substr($key, 0, self::KEY_MAX - 41) . '#' . sha1($key);
    }
}
