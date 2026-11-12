<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Support\ContractsDoc;

final class InfrastructureDocTest extends TestCase
{
    public function testTheSignatureReferenceMatchesTheCode(): void
    {
        self::assertTrue(
            ContractsDoc::current(ContractsDoc::INFRASTRUCTURE) === ContractsDoc::render(ContractsDoc::INFRASTRUCTURE),
            'docs/INFRASTRUCTURE.md is stale: run php bin/sync-generated.php'
        );
    }

    public function testTheGuideNamesEveryTable(): void
    {
        $document = (string) file_get_contents(ContractsDoc::path(ContractsDoc::INFRASTRUCTURE));

        foreach (array_keys(Migrator::standard(PdoDatabase::inMemory(), new ArrayStore())->tables()) as $table) {
            self::assertStringContainsString('`' . $table . '`', $document, "the table $table is missing from the guide");
        }
    }
}
