<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;

/**
 * The fourth field lock, read in the source: only the write path issues tokens and sends writes.
 * - Nothing outside src/Application/Write names the write gate (so nothing else issues a token).
 * - The step sender is the one place of the plugin that calls a write method of a connector.
 * - No code builds a write request by hand outside the connectors and the schedule.
 */
final class WriteSourceRulesTest extends TestCase
{
    private const WRITES = ['writeCalendar', 'writeOverrides', 'deleteOverrides', 'updateListing', 'requestRecalculation'];

    /**
     * @return list<string> plugin source files outside the given folder
     */
    private static function filesOutside(string $folder): array
    {
        return array_values(array_filter(
            SourceScan::phpFiles(Fixtures::pluginRoot() . '/src'),
            static fn (string $file): bool => !str_contains($file, '/src/' . $folder . '/')
        ));
    }

    public function testOnlyTheWritePathKnowsTheWriteGate(): void
    {
        $found = [];
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        foreach (self::filesOutside('Application/Write') as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], $names, true) && preg_match('/\bWriteGate$/', $token[1]) === 1) {
                    $found[] = basename($file);
                }
            }
        }

        self::assertSame([], $found);
    }

    public function testOnlyTheStepSenderCallsAWriteMethodOfAConnector(): void
    {
        $callers = [];
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src') as $file) {
            if (str_contains($file, '/src/Connectors/')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            foreach (self::WRITES as $write) {
                if (str_contains($source, '->' . $write . '(')) {
                    $callers[basename($file)] = true;
                }
            }
        }

        self::assertSame(['StepSender.php'], array_keys($callers));
    }

    public function testWriteRequestsAreBuiltOnlyByTheConnectorsAndTheSchedule(): void
    {
        $builders = [];
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src') as $file) {
            if (str_contains((string) file_get_contents($file), 'new WriteRequest(')) {
                $builders[] = basename($file);
            }
        }
        sort($builders);

        self::assertSame(['Beds24Connector.php', 'PriceLabsConnector.php', 'WriteStep.php'], $builders);
    }
}
