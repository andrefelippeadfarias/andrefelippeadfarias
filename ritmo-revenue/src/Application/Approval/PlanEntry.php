<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use InvalidArgumentException;

/**
 * One entry of a plan as a person approves it: a room night written or deleted, or a listing
 * value, with exactly what will be sent (whole percentages, stay, arrival and departure days) and
 * the order it cites. Explanations are not part of it: a plan rebuilt later with the same entries
 * is the same plan to the person who approved it.
 */
final class PlanEntry
{
    public const ITEM = 'item';
    public const DELETION = 'deletion';
    public const LISTING = 'listing';

    /**
     * @param string                          $subject the night ("YYYY-MM-DD") or the listing field
     * @param array<string, int|float|string> $fields  what is sent
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $roomCode,
        public readonly string $subject,
        public readonly array $fields = [],
        public readonly ?string $orderId = null,
    ) {
        if (!in_array($kind, [self::ITEM, self::DELETION, self::LISTING], true)) {
            throw new InvalidArgumentException(sprintf('Unknown entry kind "%s".', $kind));
        }
    }

    public function key(): string
    {
        return $this->kind . '|' . $this->roomCode . '|' . $this->subject;
    }

    /**
     * Entries approved or refused together: a deletion and the item that writes its night again.
     */
    public function group(): string
    {
        return $this->kind === self::LISTING ? $this->key() : 'night|' . $this->roomCode . '|' . $this->subject;
    }

    /**
     * Same key, same fields sent, same order.
     */
    public function sameAs(self $other): bool
    {
        return $this->key() === $other->key() && $this->orderId === $other->orderId && self::canonical($this->fields) === self::canonical($other->fields);
    }

    /**
     * @return array{key: string, kind: string, room: string, subject: string, fields: array<string, int|float|string>, order_id: string|null, group: string}
     */
    public function toArray(): array
    {
        $fields = $this->fields;
        ksort($fields);

        return ['key' => $this->key(), 'kind' => $this->kind, 'room' => $this->roomCode, 'subject' => $this->subject, 'fields' => $fields, 'order_id' => $this->orderId, 'group' => $this->group()];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException on a malformed entry
     */
    public static function fromArray(array $data): self
    {
        $fields = $data['fields'] ?? [];
        if (!is_array($fields)) {
            throw new InvalidArgumentException('The fields of an entry must be a map.');
        }

        return new self((string) ($data['kind'] ?? ''), (string) ($data['room'] ?? ''), (string) ($data['subject'] ?? ''), $fields, isset($data['order_id']) ? (string) $data['order_id'] : null);
    }

    /**
     * @param array<string, int|float|string> $fields
     */
    private static function canonical(array $fields): string
    {
        ksort($fields);

        return (string) json_encode($fields, JSON_PRESERVE_ZERO_FRACTION);
    }
}
