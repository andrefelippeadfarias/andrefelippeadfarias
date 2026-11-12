<?php
/**
 * Rewrites the generated signature reference of docs/INFRASTRUCTURE.md.
 *
 *   php tests/Unit/Infrastructure/Support/sync-doc.php
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

echo \Ritmo\Tests\Unit\Infrastructure\Support\InfrastructureDoc::sync()
    ? "docs/INFRASTRUCTURE.md updated\n"
    : "docs/INFRASTRUCTURE.md already up to date\n";
