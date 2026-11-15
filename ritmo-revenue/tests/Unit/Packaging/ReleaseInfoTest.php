<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\Package\ReleaseInfo;

require_once dirname(__DIR__, 3) . '/bin/i18n/Po.php';
require_once dirname(__DIR__, 3) . '/bin/package/ReleaseInfo.php';

/**
 * What must agree before a release: versions, requirements, changelogs, translations.
 */
final class ReleaseInfoTest extends TestCase
{
    private const RELEASE = '1.0.0';

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            TempTree::remove($this->dir);
        }
    }

    public function testReadmeAndChangelogDescribeTheRelease(): void
    {
        $root = Fixtures::pluginRoot();
        $readme = (string) file_get_contents($root . '/readme.txt');
        $main = (string) file_get_contents($root . '/ritmo-revenue.php');

        self::assertSame(self::RELEASE, ReleaseInfo::header($readme, 'Stable tag'));
        self::assertSame(ReleaseInfo::header($main, 'Requires at least'), ReleaseInfo::header($readme, 'Requires at least'));
        self::assertSame(ReleaseInfo::header($main, 'Requires PHP'), ReleaseInfo::header($readme, 'Requires PHP'));
        self::assertMatchesRegularExpression('/^= ' . preg_quote(self::RELEASE, '/') . ' =$/m', $readme);
        self::assertMatchesRegularExpression('/^## \[' . preg_quote(self::RELEASE, '/') . '\]/m', (string) file_get_contents($root . '/CHANGELOG.md'));
        self::assertLessThanOrEqual(150, strlen(trim(explode("\n\n", explode("Stable tag: " . self::RELEASE, $readme)[1])[1] ?? '')), 'the short description of readme.txt has at most 150 characters');
    }

    /**
     * The integration writes the release's version into the plugin header and RITMO_VERSION; until
     * then the only problems may be those two.
     */
    public function testTheRepositoryIsReadyForTheRelease(): void
    {
        $root = Fixtures::pluginRoot();
        $problems = ReleaseInfo::problems($root);
        if (ReleaseInfo::version($root) !== self::RELEASE) {
            $version = ReleaseInfo::version($root);
            self::assertSame([
                sprintf('The "Stable tag" of readme.txt is "%s" but the plugin header says "%s".', self::RELEASE, $version),
                "The changelog of readme.txt has no entry \"= $version =\".",
                "CHANGELOG.md has no entry \"## $version\".",
            ], $problems);
            self::markTestIncomplete('The plugin header and RITMO_VERSION still say ' . $version . ': the integration sets ' . self::RELEASE . '.');
        }
        self::assertSame([], $problems);
    }

    public function testEveryDisagreementIsNamed(): void
    {
        $this->dir = TempTree::make([
            'ritmo-revenue.php' => "<?php\n/**\n * Plugin Name: X\n * Version:           1.2.0\n * Requires at least: 6.5\n * Requires PHP:      8.1\n * Text Domain:       ritmo-revenue\n */\ndefine('RITMO_VERSION', '1.1.0');\n",
            'readme.txt' => "=== X ===\nRequires at least: 6.4\nRequires PHP: 8.1\nStable tag: 1.1.0\n\nShort.\n\n== Description ==\n\n== Installation ==\n\n== Changelog ==\n\n= 1.1.0 =\n* old\n",
            'CHANGELOG.md' => "# Changelog\n\n## [1.1.0]\n",
        ]);

        self::assertSame([
            'RITMO_VERSION is "1.1.0" but the plugin header says "1.2.0".',
            'The "Stable tag" of readme.txt is "1.1.0" but the plugin header says "1.2.0".',
            '"Requires at least" differs between the plugin header ("6.5") and readme.txt ("6.4").',
            'readme.txt has no "== Frequently Asked Questions ==" section.',
            'The changelog of readme.txt has no entry "= 1.2.0 =".',
            'CHANGELOG.md has no entry "## 1.2.0".',
        ], ReleaseInfo::problems($this->dir));
    }

    public function testAgreeingSourcesHaveNoProblem(): void
    {
        $this->dir = TempTree::make([
            'ritmo-revenue.php' => "<?php\n/**\n * Version: 2.0.0-beta.1\n * Requires at least: 6.5\n * Requires PHP: 8.1\n * Text Domain: ritmo-revenue\n */\ndefine( 'RITMO_VERSION', '2.0.0-beta.1' );\n",
            'readme.txt' => "=== X ===\nRequires at least: 6.5\nRequires PHP: 8.1\nStable tag: 2.0.0-beta.1\n\n== Description ==\n== Installation ==\n== Frequently Asked Questions ==\n== Changelog ==\n= 2.0.0-beta.1 =\n",
            'CHANGELOG.md' => "## 2.0.0-beta.1 - 2027-01-01\n",
        ]);

        self::assertSame([], ReleaseInfo::problems($this->dir));
        self::assertSame('2.0.0-beta.1', ReleaseInfo::version($this->dir));
    }

    public function testAVersionMustLookLikeOne(): void
    {
        $this->dir = TempTree::make(['ritmo-revenue.php' => "<?php\n// Version: next\ndefine('RITMO_VERSION', 'next');\n"]);

        self::assertContains('The plugin header has no version of the form 1.2.3 ("next").', ReleaseInfo::problems($this->dir));
    }

    public function testTheTranslationOfEveryTextIsChecked(): void
    {
        $pot = "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Save\"\nmsgstr \"\"\n\nmsgid \"%d night\"\nmsgid_plural \"%d nights\"\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\nmsgctxt \"menu\"\nmsgid \"Rules\"\nmsgstr \"\"\n\nmsgid \"Hello %s\"\nmsgstr \"\"\n\nmsgid \"Fuzzy\"\nmsgstr \"\"\n";
        $po = "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Save\"\nmsgstr \"Salvar\"\n\nmsgid \"%d night\"\nmsgid_plural \"%d nights\"\nmsgstr[0] \"%d noite\"\nmsgstr[1] \"\"\n\nmsgid \"Hello %s\"\nmsgstr \"Olá\"\n\n#, fuzzy\nmsgid \"Fuzzy\"\nmsgstr \"Difuso\"\n";

        self::assertSame([
            '"%d night" is not translated',
            '"Rules" is not in the translation',
            '"Hello %s" has other placeholders in the translation',
            '"Fuzzy" is marked fuzzy',
        ], ReleaseInfo::untranslated($pot, $po));
        self::assertCount(2, ReleaseInfo::untranslated($pot, $po, 2), 'the list stops at the limit');
    }

    public function testTheCommittedTranslationCoversTheCommittedPot(): void
    {
        $root = Fixtures::pluginRoot();

        self::assertSame([], ReleaseInfo::untranslated((string) file_get_contents($root . '/languages/ritmo-revenue.pot'), (string) file_get_contents($root . '/languages/ritmo-revenue-pt_BR.po')));
    }
}
