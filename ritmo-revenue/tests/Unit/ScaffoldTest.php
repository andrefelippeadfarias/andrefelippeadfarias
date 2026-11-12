<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ritmo\Plugin;
use Ritmo\Tests\Support\ContractsDoc;
use Ritmo\Tests\Support\Fixtures;

/**
 * The plugin skeleton: entry files, autoloader, developer scripts and documents.
 */
final class ScaffoldTest extends TestCase
{
    private static function root(): string
    {
        return Fixtures::pluginRoot();
    }

    /**
     * Runs PHP code in a separate process and returns [exit code, output].
     *
     * @return array{int, string}
     */
    private static function runPhp(string $code): array
    {
        $output = [];
        $exit = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    public function testPluginHeaderDeclaresTheRequirements(): void
    {
        $header = (string) file_get_contents(self::root() . '/ritmo-revenue.php', false, null, 0, 8192);

        self::assertMatchesRegularExpression('/^ \* Plugin Name:\s+Ritmo Revenue$/m', $header);
        self::assertMatchesRegularExpression('/^ \* Requires at least:\s+6\.5$/m', $header);
        self::assertMatchesRegularExpression('/^ \* Requires PHP:\s+8\.1$/m', $header);
        self::assertMatchesRegularExpression('/^ \* Text Domain:\s+ritmo-revenue$/m', $header);
        self::assertMatchesRegularExpression('/^ \* Version:\s+\d+\.\d+\.\d+$/m', $header);
    }

    public function testMainFileDefinesConstantsLoadsTheAutoloaderAndHooksBoot(): void
    {
        $main = self::root() . '/ritmo-revenue.php';
        $code = 'define("ABSPATH", "/tmp/"); $hooks = [];'
            . 'function add_action($hook, $callback) { global $hooks; $hooks[] = [$hook, $callback]; }'
            . 'require ' . var_export($main, true) . ';'
            . 'call_user_func($hooks[0][1]);'
            . 'echo json_encode(["version" => RITMO_VERSION, "file" => RITMO_FILE, "dir" => RITMO_DIR, "hooks" => $hooks,'
            . ' "booted" => Ritmo\Plugin::isBooted(), "autoload" => interface_exists("Ritmo\\\\Domain\\\\Support\\\\Clock")]);';

        [$exit, $output] = self::runPhp($code);
        $result = json_decode($output, true);

        self::assertSame(0, $exit, $output);
        self::assertIsArray($result, $output);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $result['version']);
        self::assertSame($main, $result['file']);
        self::assertSame(self::root(), $result['dir']);
        self::assertSame([['plugins_loaded', ['Ritmo\\Plugin', 'boot']]], $result['hooks']);
        self::assertTrue($result['booted']);
        self::assertTrue($result['autoload']);
        self::assertStringContainsString($result['version'], (string) file_get_contents($main, false, null, 0, 8192), 'RITMO_VERSION and the header version must agree');
    }

    public function testEntryFilesDoNothingWhenOpenedDirectly(): void
    {
        foreach (['ritmo-revenue.php', 'uninstall.php'] as $file) {
            [$exit, $output] = self::runPhp('require ' . var_export(self::root() . '/' . $file, true) . '; echo "REACHED";');

            self::assertSame(0, $exit, $file);
            self::assertSame('', $output, "$file must exit silently outside WordPress");
        }
    }

    public function testUninstallIsSafeInsideWordPress(): void
    {
        [$exit, $output] = self::runPhp('define("WP_UNINSTALL_PLUGIN", "ritmo-revenue/ritmo-revenue.php"); require ' . var_export(self::root() . '/uninstall.php', true) . '; echo "DONE";');

        self::assertSame(0, $exit);
        self::assertSame('DONE', $output, 'uninstall.php must run without output or errors');
    }

    public function testBootIsIdempotent(): void
    {
        Plugin::boot();
        Plugin::boot();

        self::assertTrue(Plugin::isBooted());
    }

    public function testAutoloaderIgnoresWhatIsNotItsOwn(): void
    {
        self::assertTrue(class_exists(\Ritmo\Domain\Support\Num::class));
        self::assertFalse(class_exists('Ritmo\\Domain\\DoesNotExist'));
        self::assertFalse(class_exists('RitmoLookalike\\Domain\\Support\\Num'));
        self::assertFalse(class_exists('Other\\Vendor\\Thing'));
    }

    public function testGitignoreKeepsToolsAndBuildOutputOutOfGit(): void
    {
        $lines = file(self::root() . '/.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach (['/tools/', '/.wp-test/', '/dist/', '*.phar', '/tests/e2e/screenshots/'] as $entry) {
            self::assertContains($entry, $lines);
        }
        self::assertNotContains('tools/', $lines, 'an unanchored "tools/" would also ignore tests/fixtures/tools/');
    }

    public function testGitIgnoresDownloadedToolsButNotTheFixtureTools(): void
    {
        $ignored = static function (string $path): bool {
            exec('git -C ' . escapeshellarg(self::root()) . ' check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null', $output, $exit);

            return $exit === 0;
        };
        exec('git -C ' . escapeshellarg(self::root()) . ' rev-parse --is-inside-work-tree 2>/dev/null', $output, $exit);
        self::assertSame(0, $exit, 'the plugin folder is expected to live inside a git work tree');

        foreach (['tools/phpunit.phar', 'tools/.phpunit.cache/x', 'dist/ritmo-revenue-0.1.0.zip', '.wp-test/wp-config.php', 'tests/e2e/screenshots/a.png', 'anything.phar'] as $path) {
            self::assertTrue($ignored($path), "$path must be ignored");
        }
        foreach (['tests/fixtures/tools/dump_golden.py', 'tests/fixtures/tools/make_parity_preset.py', 'tests/fixtures/golden/MANIFEST.json', 'src/Plugin.php', 'presets/default.json'] as $path) {
            self::assertFalse($ignored($path), "$path must be versioned");
        }
    }

    public function testDeveloperScriptsAreRunnable(): void
    {
        $script = self::root() . '/bin/test.sh';
        exec('bash -n ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

        self::assertSame(0, $exit, implode("\n", $output));
        self::assertTrue(is_executable($script), 'bin/test.sh must be executable');
        self::assertStringContainsString('tests/phpunit.xml', (string) file_get_contents($script));
        self::assertFileExists(self::root() . '/bin/sync-generated.php');
    }

    public function testComposerFileDeclaresNoRuntimeDependency(): void
    {
        $composer = json_decode((string) file_get_contents(self::root() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['php'], array_keys($composer['require']));
        self::assertSame(['Ritmo\\' => 'src/'], $composer['autoload']['psr-4']);
    }

    public function testDocumentsExist(): void
    {
        foreach (['PLANO.md', 'ARCHITECTURE.md', 'CONTRACTS.md'] as $document) {
            self::assertGreaterThan(2000, filesize(self::root() . '/docs/' . $document), $document);
        }
        self::assertFileExists(self::root() . '/README.md');
    }

    public function testTheSignatureReferenceOfTheContractsDocumentIsCurrent(): void
    {
        self::assertSame(
            ContractsDoc::render(),
            ContractsDoc::current(),
            'docs/CONTRACTS.md is out of step with the code: run "php bin/sync-generated.php"'
        );
        self::assertGreaterThan(300, substr_count(ContractsDoc::current(), "\n"));
    }
}
