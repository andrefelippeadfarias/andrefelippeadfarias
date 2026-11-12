<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;

/**
 * Rules of the infrastructure module that a behaviour test cannot see: who may read the
 * clock, sleep, open a connection or call WordPress. Each has exactly one door.
 */
final class InfrastructureRulesTest extends TestCase
{
    private const NETWORK_FUNCTIONS = [
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_context_create', 'socket_create', 'fopen', 'file_get_contents',
        'file', 'readfile', 'copy', 'get_headers', 'mail',
    ];
    private const SLEEP_FUNCTIONS = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];

    public function testThereAreFilesToCheck(): void
    {
        self::assertGreaterThan(50, count($this->files()));
    }

    public function testTheSystemClockIsReadOnlyThroughTheClock(): void
    {
        foreach ($this->files() as $relative => $code) {
            self::assertSame([], SourceScan::clockReads($code), "$relative reads the system clock; inject Clock instead");
        }
    }

    public function testOnlyTheSystemSleeperSleeps(): void
    {
        self::assertSame(['Support/SystemSleeper.php'], $this->filesCalling(static fn (string $name): bool => in_array($name, self::SLEEP_FUNCTIONS, true)));
    }

    public function testOnlyTheTwoHttpClientsOpenConnections(): void
    {
        self::assertSame(['Http/CurlHttpClient.php'], $this->filesCalling(static fn (string $name): bool => str_starts_with($name, 'curl_')));
        self::assertSame(['Http/WpHttpClient.php'], $this->filesCalling(static fn (string $name): bool => str_starts_with($name, 'wp_remote_') || str_starts_with($name, 'wp_safe_remote_')));
        self::assertSame([], $this->filesCalling(static fn (string $name): bool => in_array($name, self::NETWORK_FUNCTIONS, true)));
    }

    public function testWordPressIsReachedOnlyThroughTheNamedAdapters(): void
    {
        $mentioning = [];
        foreach ($this->files() as $relative => $code) {
            if (SourceScan::wordPressMentions($code) !== []) {
                $mentioning[] = $relative;
            }
        }

        self::assertSame(
            ['Db/Migration/Migrator.php', 'Db/WpdbDatabase.php', 'Http/WpHttpClient.php', 'Store/OptionsStore.php'],
            $mentioning,
            'a new class that needs WordPress must be reviewed and added here'
        );
    }

    public function testNoCodeEvaluatesOrUnserializesData(): void
    {
        self::assertSame([], $this->filesCalling(static fn (string $name): bool => in_array($name, ['unserialize', 'eval', 'assert', 'create_function', 'extract', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'], true)));
    }

    /**
     * Source of every file of the module, by path relative to src/Infrastructure.
     *
     * @return array<string, string>
     */
    private function files(): array
    {
        $root = Fixtures::pluginRoot() . '/src/Infrastructure/';
        $files = [];
        foreach (SourceScan::phpFiles($root) as $file) {
            $files[substr($file, strlen($root))] = (string) file_get_contents($file);
        }
        ksort($files);

        return $files;
    }

    /**
     * Files that call a function whose lowercase name satisfies $matches (method calls and
     * declarations do not count).
     *
     * @param callable(string): bool $matches
     *
     * @return list<string>
     */
    private function filesCalling(callable $matches): array
    {
        $found = [];
        foreach ($this->files() as $relative => $code) {
            $tokens = array_values(array_filter(
                token_get_all($code),
                static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($tokens as $index => $token) {
                $isName = is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true);
                $previous = $tokens[$index - 1] ?? '';
                $afterMember = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
                if ($isName && !$afterMember && ($tokens[$index + 1] ?? '') === '(' && $matches(strtolower(ltrim($token[1], '\\')))) {
                    $found[] = $relative;
                    break;
                }
            }
        }

        return $found;
    }
}
