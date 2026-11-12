<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

use ReflectionClass;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SignaturePrinter;
use Ritmo\Tests\Support\SourceScan;
use RuntimeException;

/**
 * The generated part of docs/INFRASTRUCTURE.md: the exact public members of every type under
 * src/Infrastructure. A test fails when it is stale; `php tests/Unit/Infrastructure/Support/sync-doc.php`
 * rewrites it.
 */
final class InfrastructureDoc
{
    public const BEGIN = '<!-- BEGIN GENERATED SIGNATURES (php tests/Unit/Infrastructure/Support/sync-doc.php) -->';
    public const END = '<!-- END GENERATED SIGNATURES -->';

    public static function path(): string
    {
        return Fixtures::pluginRoot() . '/docs/INFRASTRUCTURE.md';
    }

    /**
     * The reference section as Markdown, markers included.
     */
    public static function render(): string
    {
        $out = [self::BEGIN, ''];
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/Infrastructure') as $file) {
            $declared = SourceScan::declarations((string) file_get_contents($file));
            /** @var class-string $class */
            $class = $declared['namespace'] . '\\' . $declared['types'][0];
            $out[] = sprintf('#### `%s` (%s)', $class, self::kind(new ReflectionClass($class)));
            $out[] = '';
            $out[] = '```php';
            array_push($out, ...(SignaturePrinter::lines($class) ?: ['// no public members']));
            $out[] = '```';
            $out[] = '';
        }
        $out[] = self::END;

        return implode("\n", $out);
    }

    /**
     * The reference section currently in the document, markers included.
     *
     * @throws RuntimeException when the document or its markers are missing
     */
    public static function current(): string
    {
        $document = (string) file_get_contents(self::path());
        $begin = strpos($document, self::BEGIN);
        $end = strpos($document, self::END);
        if ($begin === false || $end === false) {
            throw new RuntimeException('docs/INFRASTRUCTURE.md has no generated section.');
        }

        return substr($document, $begin, $end + strlen(self::END) - $begin);
    }

    /**
     * Rewrites the reference section.
     *
     * @return bool true when the document changed
     */
    public static function sync(): bool
    {
        $document = (string) file_get_contents(self::path());
        $updated = str_replace(self::current(), self::render(), $document);
        if ($updated === $document) {
            return false;
        }
        file_put_contents(self::path(), $updated);

        return true;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private static function kind(ReflectionClass $class): string
    {
        return match (true) {
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            $class->isEnum() => 'enum',
            default => ($class->isFinal() ? 'final ' : '') . 'class'
                . ($class->getParentClass() !== false ? ' extends ' . $class->getParentClass()->getShortName() : '')
                . ($class->getInterfaceNames() !== [] ? ' implements ' . implode(', ', array_map(
                    static fn (string $name): string => substr((string) strrchr('\\' . $name, '\\'), 1),
                    array_values(array_filter($class->getInterfaceNames(), static fn (string $name): bool => str_starts_with($name, 'Ritmo\\')))
                )) : ''),
        };
    }
}
