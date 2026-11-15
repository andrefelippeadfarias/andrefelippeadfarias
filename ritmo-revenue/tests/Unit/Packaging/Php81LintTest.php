<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Packaging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\Package\Php81Lint;

require_once dirname(__DIR__, 3) . '/bin/package/Php81Lint.php';

/**
 * bin/lint-php81.php: what PHP 8.1 cannot run is found, what it can run is left alone, and every
 * PHP file the plugin ships passes.
 */
final class Php81LintTest extends TestCase
{
    public function testEveryFileThePluginShipsRunsOnPhp81(): void
    {
        $root = Fixtures::pluginRoot();
        $paths = [$root . '/ritmo-revenue.php', $root . '/uninstall.php', $root . '/src', $root . '/templates', $root . '/languages'];

        self::assertGreaterThan(1000, count(Php81Lint::phpFiles($paths)));
        self::assertSame([], Php81Lint::checkPaths($paths));
    }

    public function testTheScriptsOfTheRepositoryRunOnPhp81Too(): void
    {
        $root = Fixtures::pluginRoot();

        self::assertSame([], Php81Lint::checkPaths([$root . '/bin', $root . '/tests/e2e', $root . '/tests/fake-apis']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function newerCode(): iterable
    {
        yield 'readonly class' => ['readonly class A {}', 'readonly class (PHP 8.2)'];
        yield 'final readonly class' => ['final readonly class A {}', 'readonly class (PHP 8.2)'];
        yield 'anonymous readonly class' => ['$a = new readonly class {};', 'readonly class (PHP 8.2)'];
        yield 'constant in a trait' => ['trait T { const X = 1; }', 'constant in a trait (PHP 8.2)'];
        yield 'DNF parameter' => ['function f((A&B)|null $x): void {}', 'DNF type (PHP 8.2)'];
        yield 'DNF return type' => ['function f(): (A&B)|null { return null; }', 'DNF type (PHP 8.2)'];
        yield 'DNF property' => ['class C { public (A&B)|null $p = null; }', 'DNF type (PHP 8.2)'];
        yield 'null as a type' => ['function f(): null { return null; }', 'null as a type of its own (PHP 8.2)'];
        yield 'false as a type' => ['function f(): false { return false; }', 'false as a type of its own (PHP 8.2)'];
        yield 'true as a parameter type' => ['function f(true $t): void {}', 'true as a type of its own (PHP 8.2)'];
        yield 'true in a union' => ['function f(int|true $t): void {}', 'true in a union type (PHP 8.2)'];
        yield 'true in an arrow function' => ['$f = fn (): true => true;', 'true as a type of its own (PHP 8.2)'];
        yield 'enum property in a constant' => ['class C { const Y = E::B->name; }', 'property fetch (->) in a constant expression (PHP 8.2)'];
        yield 'enum property in a default value' => ['function f(string $x = E::A->value): void {}', 'property fetch (->) in a default value (PHP 8.2)'];
        yield 'typed class constant' => ['class C { const string NAME = "x"; }', 'typed class constant (PHP 8.3)'];
        yield 'typed constant with modifiers' => ['class C { final public const ?int N = null; }', 'typed class constant (PHP 8.3)'];
        yield 'dynamic class constant fetch' => ['$v = C::{$name};', 'dynamic class constant fetch ::{} (PHP 8.3)'];
        yield 'static variable from a call' => ['function f(): void { static $c = compute(); }', 'static variable initialised with an expression (PHP 8.3)'];
        yield 'static variable from a variable' => ['function f(int $a): void { static $c = $a; }', 'static variable initialised with an expression (PHP 8.3)'];
        yield 'json_validate' => ['$ok = json_validate($text);', 'json_validate() needs PHP 8.3'];
        yield 'fully qualified mb_str_pad' => ['$s = \mb_str_pad("a", 3);', 'mb_str_pad() needs PHP 8.3'];
        yield 'array_find' => ['$x = array_find($list, $test);', 'array_find() needs PHP 8.4'];
        yield 'ini_parse_quantity' => ['$n = ini_parse_quantity("2M");', 'ini_parse_quantity() needs PHP 8.2'];
        yield 'Randomizer imported' => ["namespace N;\nuse Random\\Randomizer;\n\$r = new Randomizer();", 'class random\randomizer needs PHP 8.2'];
        yield 'date exception caught' => ['try { f(); } catch (\DateMalformedStringException $e) {}', 'class datemalformedstringexception needs PHP 8.3'];
        yield 'date exception aliased' => ["namespace N;\nuse DateMalformedStringException as Bad;\nthrow new Bad('x');", 'class datemalformedstringexception needs PHP 8.3'];
        yield 'new static method' => ['$d = \DateTimeImmutable::createFromTimestamp(1);', 'datetimeimmutable::createfromtimestamp() needs PHP 8.4'];
    }

    #[DataProvider('newerCode')]
    public function testNewerSyntaxAndLibraryAreFound(string $code, string $message): void
    {
        $problems = Php81Lint::check("<?php\n" . $code);

        self::assertNotSame([], $problems, $code);
        self::assertContains(substr($problems[0], 0, strpos($problems[0], ':') + 2) . $message, $problems, implode(' | ', $problems));
    }

    public function testEachProblemNamesItsLine(): void
    {
        self::assertSame(['3: json_validate() needs PHP 8.3', '5: readonly class (PHP 8.2)'], Php81Lint::check("<?php\n\n\$a = json_validate('1');\n\nreadonly class A {}\n"));
    }

    public function testWhatPhp81RunsIsLeftAlone(): void
    {
        $code = <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace N;
            use Ritmo\Domain\Support\Clock;
            enum Kind: string { case A = 'a'; case B = 'b'; const DEFAULT = self::A; }
            interface I { public function f(): static; }
            abstract class Base { abstract protected function g(): int|false; }
            final class C extends Base implements I {
                public const LIMIT = 10;
                private static ?self $cache = null;
                public static $plain = [1, 2];
                public function __construct(private readonly Clock $clock, public readonly A&B $both, protected ?int $n = null, private array $list = new \ArrayObject()) {}
                public function f(): static { return $this; }
                protected function g(): int|false { return self::LIMIT & self::FLAGS | 4 ? 1 : false; }
                public function h(null|string $s, \Closure ...$rest): never { throw new \RuntimeException((string) $s); }
                public function i(): void {
                    static $count = 0;
                    static $list = [self::LIMIT, 2];
                    static $object = new \stdClass();
                    static::$cache = $this;
                    $f = strlen(...);
                    $g = static fn (int $x): int => $x * 2;
                    $h = function () use ($f): ?int { return null; };
                    $flags = (FLAG_A & FLAG_B) | FLAG_C;
                    $text = "{$this->n} and ${f}";
                    $m = $this->json_validate('x');
                    $s = Other::array_find('x');
                    if (function_exists('json_validate')) { $ok = json_validate('{}'); }
                    $d = new \DateTimeImmutable('2026-01-01');
                    try { $d->modify('+1 day'); } catch (\Exception $e) {}
                }
                #[\SensitiveParameter] #[\Override]
                public function json_validate(string $x): bool { return true; }
            }
            trait T { public function t(): void {} public static $counter = 0; }
            const TOP = 1;
            use function N\helper;
            use const N\VALUE;
            function array_find_in(array $a): mixed { return $a[0] ?? null; }
            PHP;

        self::assertSame([], Php81Lint::check($code));
    }

    public function testAClassOfTheSameNameInTheFilesNamespaceIsNotTheNewOne(): void
    {
        self::assertSame([], Php81Lint::check("<?php\nnamespace N;\n\$e = new DateMalformedStringException();\n"));
        self::assertNotSame([], Php81Lint::check("<?php\n\$e = new DateMalformedStringException();\n"), 'in the global namespace it is the PHP 8.3 class');
    }
}
