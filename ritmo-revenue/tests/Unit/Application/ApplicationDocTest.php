<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\ContractsDoc;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;

final class ApplicationDocTest extends TestCase
{
    public function testTheSignatureReferenceIsCurrent(): void
    {
        self::assertTrue(
            ContractsDoc::render(ContractsDoc::APPLICATION) === ContractsDoc::current(ContractsDoc::APPLICATION),
            'docs/APPLICATION.md is stale: run php bin/sync-generated.php'
        );
    }

    public function testTheGuideNamesEveryClassOfTheLayer(): void
    {
        $text = (string) file_get_contents(ContractsDoc::path(ContractsDoc::APPLICATION));
        $guide = substr($text, 0, (int) strpos($text, ContractsDoc::BEGIN));

        $missing = [];
        foreach (['Application', 'WordPress', 'Admin', 'Rest'] as $folder) {
            foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/' . $folder) as $file) {
                $class = basename($file, '.php');
                if (!str_contains($guide, $class)) {
                    $missing[] = $class;
                }
            }
        }

        self::assertSame([], $missing, 'the guide of docs/APPLICATION.md does not mention these classes');
    }

    public function testTheGuideListsEveryStageAndEveryRoute(): void
    {
        $guide = (string) file_get_contents(ContractsDoc::path(ContractsDoc::APPLICATION));

        foreach (['collect', 'measure', 'decide', 'validate', 'shadow'] as $stage) {
            self::assertStringContainsString('| `' . $stage . '` |', $guide);
        }
        foreach (['GET /status', 'GET /properties/{id}/occupancy', 'POST /properties/{id}/shadow-run', 'GET /runs/{id}'] as $route) {
            self::assertStringContainsString('`' . $route . '`', $guide);
        }
    }
}
