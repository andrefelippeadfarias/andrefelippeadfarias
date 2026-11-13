<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

/**
 * Where the valid rows of a previewed price file wait for the confirmation (a short-lived
 * transient in WordPress). Never the file itself.
 */
interface PreviewStore
{
    /**
     * @param array<string, mixed> $payload plain values only
     */
    public function put(string $token, array $payload, int $ttlSeconds): void;

    /**
     * @return array<string, mixed>|null null when unknown or expired
     */
    public function get(string $token): ?array;

    public function delete(string $token): void;
}
