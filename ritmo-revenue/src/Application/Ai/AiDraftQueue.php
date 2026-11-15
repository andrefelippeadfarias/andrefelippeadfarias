<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

use InvalidArgumentException;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Support\Clock;

/**
 * The drafts of the assistant waiting for a person: each one is shown with its summary until a
 * person opens it in the editor (the order form or the review of the rules) or discards it. A draft
 * here changes nothing; it expires after 14 days. At most 30 are kept (the oldest go first).
 */
final class AiDraftQueue
{
    public const KEY = 'ai_drafts';

    public const KEEP = 30;

    public const LIFETIME_DAYS = 14;

    public const PENDING = 'pending';
    public const OPENED = 'opened';
    public const DISCARDED = 'discarded';

    public function __construct(private readonly KeyValueStore $store, private readonly Clock $clock)
    {
    }

    /**
     * @return string the draft's id
     */
    public function add(string $propertyId, int $userId, string $request, AiDraft $draft): string
    {
        $id = bin2hex(random_bytes(8));
        $rows = $this->rows();
        $rows[] = [
            'id' => $id,
            'property' => $propertyId,
            'user' => $userId,
            'created_at' => $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
            'request' => mb_substr($request, 0, 1000),
            'status' => self::PENDING,
            'draft' => $draft->toArray(),
        ];
        $this->store->set(self::KEY, array_slice($rows, -self::KEEP));

        return $id;
    }

    /**
     * @return array{id: string, property: string, user: int, created_at: string, request: string, status: string, draft: AiDraft}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                return self::read($row);
            }
        }

        return null;
    }

    /**
     * The drafts of a property still waiting for a person, newest first.
     *
     * @return list<array{id: string, property: string, user: int, created_at: string, request: string, status: string, draft: AiDraft}>
     */
    public function pending(string $propertyId): array
    {
        $out = [];
        foreach (array_reverse($this->rows()) as $row) {
            if ($row['property'] === $propertyId && $row['status'] === self::PENDING) {
                $read = self::read($row);
                if ($read !== null) {
                    $out[] = $read;
                }
            }
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException for an unknown draft or one no longer waiting
     */
    public function close(string $id, string $status): void
    {
        if (!in_array($status, [self::OPENED, self::DISCARDED], true)) {
            throw new InvalidArgumentException('A draft is closed as opened or discarded.');
        }
        $rows = $this->rows();
        foreach ($rows as $index => $row) {
            if ($row['id'] === $id && $row['status'] === self::PENDING) {
                $rows[$index]['status'] = $status;
                $this->store->set(self::KEY, $rows);

                return;
            }
        }

        throw new InvalidArgumentException('That draft is not waiting any more.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $stored = $this->store->getFresh(self::KEY);
        $limit = $this->clock->now()->modify('-' . self::LIFETIME_DAYS . ' days')->format('Y-m-d\TH:i:s\Z');

        return array_values(array_filter(is_array($stored) ? $stored : [], static fn (mixed $row): bool => is_array($row)
            && is_string($row['id'] ?? null) && preg_match('/^[a-f0-9]{16}$/', $row['id']) === 1
            && is_string($row['property'] ?? null) && is_string($row['status'] ?? null) && is_array($row['draft'] ?? null)
            && is_string($row['created_at'] ?? null) && $row['created_at'] >= $limit));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, property: string, user: int, created_at: string, request: string, status: string, draft: AiDraft}|null
     */
    private static function read(array $row): ?array
    {
        try {
            $draft = AiDraft::fromArray($row['draft']);
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['id' => $row['id'], 'property' => $row['property'], 'user' => (int) ($row['user'] ?? 0), 'created_at' => $row['created_at'], 'request' => (string) ($row['request'] ?? ''), 'status' => $row['status'], 'draft' => $draft];
    }
}
