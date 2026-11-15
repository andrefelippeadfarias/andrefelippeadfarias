<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * One rule setting a draft of the assistant would change: the concrete path, its schema key and
 * label, the value in force and the proposed one (typed as the schema reads it).
 */
final class RuleChange
{
    public function __construct(
        public readonly string $path,
        public readonly string $key,
        public readonly string $label,
        public readonly mixed $before,
        public readonly int|float|bool|string $after,
    ) {
    }

    /**
     * @return array{path: string, key: string, label: string, before: mixed, after: int|float|bool|string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'key' => $this->key, 'label' => $this->label, 'before' => $this->before, 'after' => $this->after];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $after = $data['after'] ?? null;
        if (!is_string($data['path'] ?? null) || !is_string($data['key'] ?? null) || !(is_int($after) || is_float($after) || is_bool($after) || is_string($after))) {
            return null;
        }

        return new self($data['path'], $data['key'], (string) ($data['label'] ?? ''), $data['before'] ?? null, $after);
    }
}
