<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;

/**
 * Rules of the architecture that code review alone would let slip:
 *
 * - src/Domain and src/Contracts are pure PHP: nothing from WordPress.
 * - The domain reads time only through Clock and rounds only through Num.
 * - Plugin code and its documentation carry nothing specific to the owner's own properties.
 */
final class ArchitectureTest extends TestCase
{
    /**
     * Internal documents that name the owner's properties. They stay in the repository for the
     * team and are left out of the distributed zip by the packaging of milestone M5
     * (ARCHITECTURE.md, section 2).
     */
    private const INTERNAL_DOCUMENTS = ['docs/PLANO.md'];

    private static function source(string $relative): string
    {
        return Fixtures::pluginRoot() . '/src/' . $relative;
    }

    private static function relative(string $file): string
    {
        return substr($file, strlen(Fixtures::pluginRoot()) + 1);
    }

    public function testTheDomainHasFilesToCheck(): void
    {
        self::assertGreaterThan(20, count(SourceScan::phpFiles(self::source('Domain'))));
        self::assertGreaterThan(10, count(SourceScan::phpFiles(self::source('Contracts'))));
    }

    public function testDomainAndContractsNeverMentionWordPress(): void
    {
        $violations = [];
        foreach (['Domain', 'Contracts'] as $layer) {
            foreach (SourceScan::phpFiles(self::source($layer)) as $file) {
                $mentions = SourceScan::wordPressMentions((string) file_get_contents($file));
                if ($mentions !== []) {
                    $violations[] = self::relative($file) . ': ' . implode(', ', $mentions);
                }
            }
        }

        self::assertSame([], $violations, 'src/Domain and src/Contracts must stay free of WordPress');
    }

    public function testTheDomainReadsTimeOnlyThroughTheClock(): void
    {
        $violations = [];
        foreach (SourceScan::phpFiles(self::source('Domain')) as $file) {
            if (str_ends_with($file, '/Domain/Support/SystemClock.php')) {
                continue;
            }
            $reads = SourceScan::clockReads((string) file_get_contents($file));
            if ($reads !== []) {
                $violations[] = self::relative($file) . ': ' . implode(', ', $reads);
            }
        }

        self::assertSame([], $violations, 'only Domain/Support/SystemClock.php may read the system clock');
    }

    public function testTheDomainRoundsOnlyThroughNum(): void
    {
        $violations = [];
        foreach (SourceScan::phpFiles(self::source('Domain')) as $file) {
            if (str_ends_with($file, '/Domain/Support/Num.php')) {
                continue;
            }
            $calls = SourceScan::roundingCalls((string) file_get_contents($file));
            if ($calls !== []) {
                $violations[] = self::relative($file) . ': ' . implode(', ', $calls);
            }
        }

        self::assertSame([], $violations, 'only Domain/Support/Num.php may call round(), ceil(), floor() or number_format()');
    }

    /**
     * A ValidatedPlan is only worth something if nothing but the Validator gets around private
     * constructors: no plugin code rebinds closures, instantiates without a constructor, opens
     * private members or unserialises objects. The Validator's own seal() is the one exception.
     */
    public function testOnlyTheValidatorGetsAroundAPrivateConstructor(): void
    {
        $forbidden = ['newInstanceWithoutConstructor', 'bind', 'bindTo', 'call', 'setAccessible', 'unserialize', 'ReflectionProperty', 'ReflectionMethod'];
        $violations = [];
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src') as $file) {
            $found = [];
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $forbidden, true)) {
                    $found[] = $token[1];
                }
            }
            if ($found !== [] && !(str_ends_with($file, '/Domain/Plan/Validator.php') && $found === ['bind'])) {
                $violations[] = self::relative($file) . ': ' . implode(', ', $found);
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function wordPressSnippets(): array
    {
        return [
            'hook' => ['<?php add_action("init", "f");', ['add_action']],
            'option' => ['<?php $v = get_option("x");', ['get_option']],
            'database global' => ['<?php global $wpdb; $wpdb->query("x");', ['$wpdb']],
            'database class' => ['<?php function f(wpdb $db) {}', ['wpdb']],
            'error class' => ['<?php return new WP_Error("x");', ['WP_Error']],
            'qualified error class' => ['<?php return new \WP_Error("x");', ['\WP_Error']],
            'rest class in a type' => ['<?php function f(\WP_REST_Request $r) {}', ['\WP_REST_Request']],
            'escaping' => ['<?php echo esc_html($x);', ['esc_html']],
            'translation' => ['<?php echo __("Text", "domain");', ['__']],
            'translation with context' => ['<?php echo _x("Text", "ctx", "domain");', ['_x']],
            'translated escaping' => ['<?php echo esc_html__("Text", "domain");', ['esc_html__']],
            'helper with the wp prefix' => ['<?php wp_remote_get($url);', ['wp_remote_get']],
            'sanitizer' => ['<?php sanitize_text_field($x);', ['sanitize_text_field']],
            'constant' => ['<?php defined("X") || exit; echo ABSPATH;', ['ABSPATH']],
            'capability check, any case' => ['<?php Current_User_Can("x");', ['Current_User_Can']],
            'scheduler' => ['<?php as_schedule_single_action(1, "h");', ['as_schedule_single_action']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('wordPressSnippets')]
    public function testTheScannerCatchesWordPress(string $code, array $expected): void
    {
        self::assertSame($expected, SourceScan::wordPressMentions($code));
    }

    public function testTheScannerIgnoresCommentsStringsAndLookalikes(): void
    {
        $code = <<<'PHP'
            <?php
            // add_action() and get_option() are WordPress; $wpdb too.
            /** Never returns a WP_Error. Uses __construct. */
            final class Example
            {
                public function __construct(private string $text = 'esc_html __( wp_remote_get') {}
                public function __toString(): string { return $this->text; }
                public function add(int $a): int { return $a + $this->option(); }
                private function option(): int { return 1; }
            }
            PHP;

        self::assertSame([], SourceScan::wordPressMentions($code));
    }

    public function testTheScannerCatchesClockReadsAndRounding(): void
    {
        self::assertSame(['time()'], SourceScan::clockReads('<?php $t = time();'));
        self::assertSame(['date()', 'strtotime()'], SourceScan::clockReads('<?php echo date("Y", strtotime("x"));'));
        self::assertSame(['microtime()'], SourceScan::clockReads('<?php $t = \microtime(true);'));
        self::assertSame(['new DateTimeImmutable(now)'], SourceScan::clockReads('<?php $d = new DateTimeImmutable();'));
        self::assertSame(['new \DateTimeImmutable(now)'], SourceScan::clockReads('<?php $d = new \DateTimeImmutable("now", $zone);'));
        self::assertSame(['new DateTime(now)'], SourceScan::clockReads('<?php $d = new DateTime;'));
        self::assertSame(['round()', 'floor()'], SourceScan::roundingCalls('<?php $a = round($x) + \floor($y);'));
        self::assertSame(['ceil()', 'number_format()'], SourceScan::roundingCalls('<?php echo ceil($x), number_format($y, 2);'));
    }

    public function testTheScannerAllowsExplicitDatesAndOwnMethods(): void
    {
        $code = <<<'PHP'
            <?php
            $a = new DateTimeImmutable($text, $zone);
            $b = new DateTimeImmutable('2026-11-11', $zone);
            $c = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
            $d = Days::date('2026-11-11');
            $e = $clock->time();
            $f = Num::round($x);
            $g = $this->floor($x);
            function date(string $x): string { return $x; }
            PHP;

        self::assertSame([], SourceScan::clockReads($code));
        self::assertSame([], SourceScan::roundingCalls($code));
    }

    public function testEveryPhpFileDeclaresStrictTypes(): void
    {
        $root = Fixtures::pluginRoot();
        $files = array_merge(
            SourceScan::phpFiles($root . '/src'),
            SourceScan::phpFiles($root . '/tests'),
            SourceScan::phpFiles($root . '/templates'),
            glob($root . '/*.php') ?: []
        );

        self::assertGreaterThan(60, count($files));
        foreach ($files as $file) {
            self::assertTrue(SourceScan::declaresStrictTypes((string) file_get_contents($file)), self::relative($file) . ' must start with declare(strict_types=1)');
        }
        self::assertFalse(SourceScan::declaresStrictTypes('<?php namespace X; declare(strict_types=1);'));
        self::assertFalse(SourceScan::declaresStrictTypes('<?php declare(strict_types=0);'));
    }

    public function testSourceFilesFollowPsr4UnderTheRitmoNamespace(): void
    {
        $src = Fixtures::pluginRoot() . '/src/';
        foreach (SourceScan::phpFiles($src) as $file) {
            $relative = substr($file, strlen($src), -4);
            if ($relative === 'autoload') {
                continue;
            }
            $declared = SourceScan::declarations((string) file_get_contents($file));
            $directory = dirname($relative);
            $namespace = 'Ritmo' . ($directory === '.' ? '' : '\\' . str_replace('/', '\\', $directory));

            self::assertSame($namespace, $declared['namespace'], "$relative.php");
            self::assertSame([basename($relative)], $declared['types'], "$relative.php must declare exactly the type it is named after");
        }
    }

    public function testPluginCodeCarriesNothingOfTheOwnersProperties(): void
    {
        $root = Fixtures::pluginRoot();
        $terms = Fixtures::json('owner-terms.json')['terms'];
        $pattern = '/(?<![\p{L}\p{N}_])(' . implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)) . ')(?![\p{L}\p{N}_])/iu';

        $files = array_merge(glob($root . '/*.php') ?: [], [$root . '/README.md']);
        foreach (['src', 'presets', 'templates', 'assets', 'docs'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $files[] = $file->getPathname();
            }
        }

        self::assertGreaterThan(40, count($files));
        self::assertContains($root . '/docs/ARCHITECTURE.md', $files, 'the documentation is scanned too');
        $violations = [];
        foreach ($files as $file) {
            if (in_array(self::relative($file), self::INTERNAL_DOCUMENTS, true)) {
                continue;
            }
            if (preg_match_all($pattern, (string) file_get_contents($file), $matches) > 0) {
                $violations[] = self::relative($file) . ': ' . implode(', ', array_unique($matches[1]));
            }
        }

        self::assertSame([], $violations, 'owner-specific names, room codes or ids found in plugin code or documentation');
        foreach (self::INTERNAL_DOCUMENTS as $document) {
            self::assertFileExists($root . '/' . $document, 'an internal document that no longer exists must leave the exclusion list');
        }
        foreach ($terms as $term) {
            self::assertSame(1, preg_match($pattern, 'rooms of ' . ucfirst($term) . ', all of them'), "the pattern must catch \"$term\"");
            self::assertSame(0, preg_match($pattern, 'x' . $term . 'x'), "the pattern must match \"$term\" as a whole word only");
        }
    }

    public function testSourceHasNoLeftoverMarkers(): void
    {
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src') as $file) {
            self::assertDoesNotMatchRegularExpression('/\b(TODO|FIXME|XXX|HACK)\b/', (string) file_get_contents($file), self::relative($file));
        }
    }
}
