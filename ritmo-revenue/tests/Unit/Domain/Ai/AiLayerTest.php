<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Ai;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;

/**
 * Where the assistant's code may reach: the domain only uses the domain; the connector only the
 * contracts, the domain and its own support; nothing of the assistant names the write gate, a
 * pricing or PMS write, a mode change or the emergency switch.
 */
final class AiLayerTest extends TestCase
{
    public function testTheDomainOfTheAssistantDependsOnTheDomainOnly(): void
    {
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/Domain/Ai') as $file) {
            preg_match_all('/^use\s+([^;]+);/m', (string) file_get_contents($file), $uses);
            foreach ($uses[1] as $use) {
                self::assertTrue(str_starts_with($use, 'Ritmo\\Domain\\') || !str_contains($use, '\\'), basename($file) . ' uses ' . $use);
            }
        }
    }

    public function testTheConnectorUsesContractsDomainAndConnectorSupportOnly(): void
    {
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/Connectors/Ai') as $file) {
            preg_match_all('/^use\s+([^;]+);/m', (string) file_get_contents($file), $uses);
            foreach ($uses[1] as $use) {
                $ok = str_starts_with($use, 'Ritmo\\Contracts\\') || str_starts_with($use, 'Ritmo\\Domain\\') || str_starts_with($use, 'Ritmo\\Connectors\\') || !str_contains($use, '\\');
                self::assertTrue($ok, basename($file) . ' uses ' . $use);
            }
        }
    }

    public function testNothingOfTheAssistantReachesAWriteAModeOrTheEmergencySwitch(): void
    {
        $forbidden = '/WriteGate|WriteToken|WriteAuthority|StepSender|writeOverrides|deleteOverrides|updateListing|writeCalendar|requestRecalculation|ModeService|EmergencySwitch|EmergencyControl|ApprovalService|RuleSetEditor|OrderDesk|->approve\(/';
        $root = Fixtures::pluginRoot();
        $files = [];
        foreach (['src/Domain/Ai', 'src/Connectors/Ai', 'src/Application/Ai', 'src/Rest/Ai', 'src/Admin/Ai'] as $folder) {
            if (is_dir($root . '/' . $folder)) {
                $files = [...$files, ...SourceScan::phpFiles($root . '/' . $folder)];
            }
        }
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression($forbidden, (string) file_get_contents($file), substr($file, strlen($root) + 1));
        }
    }
}
