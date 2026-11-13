<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use DateTimeImmutable;

/**
 * A person's unsaved changes to the rules: the candidate rules, the version and hash they
 * started from (for the three-way merge), the paths touched and when it was last changed. A
 * draft is dropped after 7 days, on save and on discard.
 */
final class RuleDraft
{
    public const LIFETIME_DAYS = 7;

    /**
     * @param array<string, mixed> $rules        the complete candidate rules
     * @param list<string>         $touched      paths changed in the draft
     * @param string               $source       editor, restore or import
     * @param int|null             $restoredFrom the version a restore started from
     */
    public function __construct(
        public readonly int $baseVersion,
        public readonly string $baseHash,
        public readonly array $rules,
        public readonly array $touched,
        public readonly DateTimeImmutable $updatedAt,
        public readonly string $source = 'editor',
        public readonly ?int $restoredFrom = null,
    ) {
    }

    /**
     * @param array<string, mixed> $rules
     * @param list<string>         $touched
     */
    public function with(array $rules, array $touched, DateTimeImmutable $at): self
    {
        return new self($this->baseVersion, $this->baseHash, $rules, array_values(array_unique([...$this->touched, ...$touched])), $at, $this->source, $this->restoredFrom);
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->updatedAt < $now->modify(sprintf('-%d days', self::LIFETIME_DAYS));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base_version' => $this->baseVersion, 'base_hash' => $this->baseHash, 'rules' => $this->rules,
            'touched_keys' => $this->touched, 'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'source' => $this->source, 'restored_from' => $this->restoredFrom,
        ];
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $at = DateTimeImmutable::createFromFormat(DATE_ATOM, (string) ($stored['updated_at'] ?? ''));
        if (!is_int($stored['base_version'] ?? null) || !is_string($stored['base_hash'] ?? null) || !is_array($stored['rules'] ?? null) || $at === false) {
            return null;
        }

        return new self(
            $stored['base_version'],
            $stored['base_hash'],
            $stored['rules'],
            array_values(array_filter((array) ($stored['touched_keys'] ?? []), 'is_string')),
            $at,
            in_array($stored['source'] ?? '', ['editor', 'restore', 'import', 'wizard'], true) ? (string) $stored['source'] : 'editor',
            is_int($stored['restored_from'] ?? null) ? $stored['restored_from'] : null,
        );
    }
}
