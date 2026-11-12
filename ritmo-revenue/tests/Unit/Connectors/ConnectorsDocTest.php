<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;

/**
 * docs/CONNECTORS.md is what the composition root and the next phases are written against:
 * it names every class of src/Connectors and every double of tests/Fakes, and the classes its
 * wiring example uses exist.
 */
final class ConnectorsDocTest extends TestCase
{
    private static function guide(): string
    {
        return (string) file_get_contents(Fixtures::pluginRoot() . '/docs/CONNECTORS.md');
    }

    /**
     * @return list<string>
     */
    private static function classesIn(string $directory): array
    {
        $names = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $names[] = $file->getBasename('.php');
            }
        }
        sort($names);

        return $names;
    }

    public function testEveryConnectorClassAndEveryDoubleIsInTheGuide(): void
    {
        $guide = self::guide();
        $classes = array_merge(self::classesIn(Fixtures::pluginRoot() . '/src/Connectors'), self::classesIn(dirname(__DIR__, 2) . '/Fakes'));

        self::assertGreaterThan(25, count($classes));
        foreach ($classes as $class) {
            self::assertSame(1, preg_match('/\b' . $class . '\b/', $guide), "docs/CONNECTORS.md does not mention $class");
        }
    }

    public function testTheClassesOfTheWiringExampleExist(): void
    {
        preg_match_all('/new ([A-Z][A-Za-z0-9]+)\(|([A-Z][A-Za-z0-9]+)::[a-zA-Z_]+/', self::guide(), $matches);
        $used = array_unique(array_filter(array_merge($matches[1], $matches[2])));

        self::assertGreaterThan(10, count($used));
        foreach ($used as $class) {
            $found = array_filter(
                ['Connectors\\Beds24', 'Connectors\\PriceLabs', 'Connectors\\Support', 'Infrastructure\\Http', 'Infrastructure\\Support'],
                static fn (string $namespace): bool => class_exists('Ritmo\\' . $namespace . '\\' . $class)
            );
            self::assertNotSame([], $found, "docs/CONNECTORS.md uses $class, which does not exist");
        }
    }
}
