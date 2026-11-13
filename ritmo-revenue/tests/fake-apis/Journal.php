<?php

declare(strict_types=1);

namespace RitmoFakeApis;

/**
 * What the simulator remembers between requests (the built-in server starts the router anew
 * for each one): the log of every request received and a little state (access tokens issued,
 * failures a test asked for). Both live in files next to each other.
 */
final class Journal
{
    private readonly string $stateFile;

    public function __construct(private readonly string $logFile)
    {
        $this->stateFile = $logFile . '.state';
    }

    /**
     * The writable world kept next to this log.
     */
    public function writeWorld(): WriteWorld
    {
        return new WriteWorld($this->logFile);
    }

    public function record(string $method, string $path, string $query, int $status): void
    {
        $line = json_encode(['method' => $method, 'path' => $path, 'query' => $query, 'status' => $status], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array{method: string, path: string, query: string, status: int}>
     */
    public function requests(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        $requests = [];
        foreach (file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $requests[] = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        }

        return $requests;
    }

    /**
     * Forgets the log and the state.
     */
    public function reset(): void
    {
        file_put_contents($this->logFile, '', LOCK_EX);
        file_put_contents($this->stateFile, '{}', LOCK_EX);
        $this->writeWorld()->reset();
    }

    public function issueAccessToken(): string
    {
        $state = $this->state();
        $number = count($state['access_tokens'] ?? []) + ($state['revoked'] ?? 0) + 1;
        $token = sprintf('sim-access-token-%04d', $number);
        $state['access_tokens'][] = $token;
        $this->save($state);

        return $token;
    }

    public function isAccessToken(string $token): bool
    {
        return in_array($token, $this->state()['access_tokens'] ?? [], true);
    }

    /**
     * Every access token issued so far stops working, as when the PMS revokes them.
     */
    public function revokeAccessTokens(): void
    {
        $state = $this->state();
        $state['revoked'] = ($state['revoked'] ?? 0) + count($state['access_tokens'] ?? []);
        $state['access_tokens'] = [];
        $this->save($state);
    }

    /**
     * @param array{method: string, path: string, status: int, headers: array<string, string>, times: int, mode: string, seconds: int} $fault
     */
    public function addFault(array $fault): void
    {
        $state = $this->state();
        $state['faults'][] = $fault;
        $this->save($state);
    }

    /**
     * The first failure asked for this method and path, used up once.
     *
     * @return array{method: string, path: string, status: int, headers: array<string, string>, times: int, mode?: string, seconds?: int}|null
     */
    public function takeFault(string $method, string $path): ?array
    {
        $state = $this->state();
        foreach ($state['faults'] ?? [] as $index => $fault) {
            if ($fault['method'] !== $method || $fault['path'] !== $path) {
                continue;
            }
            if (--$state['faults'][$index]['times'] <= 0) {
                array_splice($state['faults'], $index, 1);
            }
            $this->save($state);

            return $fault;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $raw = is_file($this->stateFile) ? (string) file_get_contents($this->stateFile) : '';
        $state = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
