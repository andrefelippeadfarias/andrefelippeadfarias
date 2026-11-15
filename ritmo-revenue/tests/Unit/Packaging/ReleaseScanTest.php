<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Packaging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\Package\DistIgnore;
use Ritmo\Tools\Package\ReleaseScan;

require_once dirname(__DIR__, 3) . '/bin/package/DistIgnore.php';
require_once dirname(__DIR__, 3) . '/bin/package/ReleaseScan.php';

/**
 * The last look before zipping: nothing of the owner's properties, no secret, only plugin files.
 * The owner's terms are read from the fixture, never written here.
 */
final class ReleaseScanTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            TempTree::remove($this->dir);
        }
    }

    public function testThePackageOfTheRepositoryIsClean(): void
    {
        $root = Fixtures::pluginRoot();
        $files = DistIgnore::fromFile($root . '/.distignore')->files($root);

        self::assertGreaterThan(1000, count($files));
        self::assertContains('readme.txt', $files);
        self::assertContains('CHANGELOG.md', $files);
        self::assertSame([], ReleaseScan::fromOwnerTermsFile(Fixtures::path('owner-terms.json'))->scan($root, $files));
    }

    public function testEveryTermOfTheOwnerIsFoundAsAWholeWordInAnyCase(): void
    {
        $terms = Fixtures::json('owner-terms.json')['terms'];
        $scan = new ReleaseScan($terms);

        foreach ($terms as $term) {
            self::assertSame([ucfirst($term)], $scan->ownerTerms('Rooms of ' . ucfirst($term) . ', all of them'), $term);
            self::assertSame([$term], $scan->ownerTerms('check_' . $term . '_rule'), "$term inside a snake_case name");
            self::assertSame([], $scan->ownerTerms('x' . $term . 'x'), "$term only as a whole word");
        }
    }

    public function testATermInABinaryFileIsFoundToo(): void
    {
        $term = Fixtures::json('owner-terms.json')['terms'][0];

        self::assertSame([$term], (new ReleaseScan([$term]))->ownerTerms("\xde\x12\x04\x95\x00" . $term . "\x00\xff"));
    }

    public function testTheReportCountsTheTermsWithoutPrintingThem(): void
    {
        $term = Fixtures::json('owner-terms.json')['terms'][0];
        $this->dir = TempTree::make(['src/A.php' => "<?php\n// " . $term . "\n"]);

        $problems = (new ReleaseScan([$term]))->scan($this->dir, ['src/A.php']);

        self::assertCount(1, $problems);
        self::assertStringContainsString("src/A.php: 1 term(s) of the owner's own properties", $problems[0]);
        self::assertStringNotContainsString($term, $problems[0]);
    }

    /**
     * Credentials are put together at run time, so that this file holds none.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        $random = 'q7Lm2Xv9Tz4Kp8Rw3Ny6Bc1Hd5Fg0Js';
        yield 'Anthropic key' => ['$key = "sk-' . 'ant-api03-' . $random . '";', 'an Anthropic API key'];
        yield 'AWS key' => ['AK' . 'IA' . 'ABCDEFGHIJKLMNOP', 'an AWS access key'];
        yield 'GitHub token' => ['gh' . 'p_' . $random . 'abcdefgh', 'a GitHub token'];
        yield 'private key' => ['-----BEGIN ' . 'PRIVATE KEY-----', 'a private key'];
        yield 'OpenSSH private key' => ['-----BEGIN OPENSSH ' . 'PRIVATE KEY-----', 'a private key'];
        yield 'JSON web token' => ['ey' . 'JhbGciOiJIUzI1NiJ9.ey' . 'JzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', 'a JSON web token'];
        yield 'test licence key' => ['define("KEY", "TEST-' . 'VALID-0001");', 'a licence key of the test provider'];
        yield 'simulator token' => ["'sim-" . "refresh-token-0001'", 'a credential of the local simulator'];
        yield 'e2e password' => ["'ritmo-e2e-" . "only-password'", 'a credential of the local simulator'];
        yield 'PriceLabs-like key in an array' => ["'api_key' => '" . $random . "'", 'a value that looks like a secret after "api_key"'];
        yield 'token in a define' => ["define('BEDS24_TOKEN', '" . $random . "');", 'a value that looks like a secret after "TOKEN"'];
        yield 'password in JSON' => ['{"password": "' . $random . '"}', 'a value that looks like a secret after "password"'];
    }

    #[DataProvider('secrets')]
    public function testSecretsAreFound(string $text, string $label): void
    {
        $found = ReleaseScan::secrets("first line\n" . $text);

        self::assertContains('2: ' . $label, $found, implode(' | ', $found));
    }

    public function testWordsThatOnlyTalkAboutSecretsAreNotSecrets(): void
    {
        $text = <<<'TXT'
            define('RITMO_SECRET_KEY', 'uma-frase-longa-e-aleatoria-com-32-caracteres-ou-mais');
            $this->vault->reveal('pricelabs_api_key');
            'token' => __('A token is saved. Leave empty to keep it.', 'ritmo-revenue'),
            "TEST-VALID-..." valid for one year; keys starting with "TEST-" in development mode
            'header' => 'X-API-Key', 'kind' => 'refresh_token_kind',
            sk-ant-
            const EMBEDDED_PUBLIC_KEY = '';
            hash('sha256', $bytes) === 'c0bc1da7aa652a04edb297eb2e94511a37a80d4452d252b0522ff70bd93fde72';
            TXT;

        self::assertSame([], ReleaseScan::secrets($text));
    }

    public function testARandomValueMixesLettersAndDigitsAndVaries(): void
    {
        self::assertTrue(ReleaseScan::looksRandom('q7Lm2Xv9Tz4Kp8Rw3Ny6Bc1Hd5Fg0Js'));
        self::assertFalse(ReleaseScan::looksRandom('short9a8b7'));
        self::assertFalse(ReleaseScan::looksRandom('aaaaaaaaaaaaaaaaaaaaaaaa111'), 'little variation');
        self::assertFalse(ReleaseScan::looksRandom('only-words-and-nothing-else'), 'no digits');
        self::assertEqualsWithDelta(1.0, ReleaseScan::entropy('abab'), 0.0001);
        self::assertSame(0.0, ReleaseScan::entropy(''));
    }

    public function testOnlyPluginFileTypesAreShipped(): void
    {
        foreach (['src/A.php', 'assets/a.js', 'assets/a.css', 'presets/default.json', 'readme.txt', 'docs/X.md', 'languages/x.mo', 'languages/x.po', 'languages/x.pot', 'assets/icon.svg', 'assets/shot.png'] as $ok) {
            self::assertNull(ReleaseScan::fileProblem($ok), $ok);
        }
        foreach (['wp-config.php', '.env', '.env.local', 'debug.log', 'error_log', 'data/site.sqlite', 'tools/phpunit.phar', 'old.zip', 'keys/update.key', 'cert.pem', 'id_rsa', 'src/A.php~', 'src/A.php.orig', 'src/A.php.rej', 'notes.bak', 'bin/run.sh', 'Makefile', 'src/x.exe'] as $bad) {
            self::assertNotNull(ReleaseScan::fileProblem($bad), $bad);
        }
    }

    public function testAnEmptyListOfTermsCannotCheckAnything(): void
    {
        $this->expectException(\RuntimeException::class);
        new ReleaseScan(['', ' ']);
    }
}
