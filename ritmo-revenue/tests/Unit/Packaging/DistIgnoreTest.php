<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\Package\DistIgnore;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/bin/package/DistIgnore.php';

/**
 * What goes into the zip: .distignore and the hidden files.
 */
final class DistIgnoreTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            TempTree::remove($this->dir);
        }
    }

    public function testTheRepositoryLeavesOutWhatIsNotThePlugin(): void
    {
        $rules = DistIgnore::fromFile(Fixtures::pluginRoot() . '/.distignore');

        foreach (['tests/Unit/ScaffoldTest.php', 'tests/fake-apis/router.php', 'tests/fixtures/owner-terms.json', 'tools/phpunit.phar', 'bin/build.sh', 'bin/package/build.php', 'docs/PLANO.md', 'docs/IMPLANTACAO.md', 'dist/ritmo-revenue-1.0.0.zip', 'composer.json', '.distignore', '.gitignore', '.wp-test/wordpress/index.php', 'src/.DS_Store', 'assets/.hidden/x.js'] as $path) {
            self::assertTrue($rules->excludes($path, false), "$path must stay out of the zip");
        }
        foreach (['ritmo-revenue.php', 'uninstall.php', 'readme.txt', 'CHANGELOG.md', 'src/Plugin.php', 'templates/admin/screens/layout.php', 'assets/screens.js', 'languages/ritmo-revenue-pt_BR.mo', 'presets/default.json', 'docs/GUIA-DO-USUARIO.md'] as $path) {
            self::assertFalse($rules->excludes($path, false), "$path belongs in the zip");
        }
    }

    public function testPatternsFollowTheGitignoreConventions(): void
    {
        $rules = new DistIgnore(['# comment', '', '/root-only.txt', 'anywhere.log', 'cache/', '/docs/internal/', '*.bak', 'a/**/z.md', 'n?te.txt']);

        self::assertTrue($rules->excludes('root-only.txt', false));
        self::assertFalse($rules->excludes('src/root-only.txt', false), 'a leading "/" anchors the pattern');
        self::assertTrue($rules->excludes('anywhere.log', false));
        self::assertTrue($rules->excludes('src/deep/anywhere.log', false), 'a name without "/" matches at any depth');
        self::assertTrue($rules->excludes('src/cache/file.php', false), 'a folder pattern leaves out what is inside');
        self::assertFalse($rules->excludes('src/cache', false), 'a folder pattern does not match a file of that name');
        self::assertTrue($rules->excludes('docs/internal/a.md', false));
        self::assertFalse($rules->excludes('src/docs/internal/a.md', false));
        self::assertTrue($rules->excludes('x/y.bak', false));
        self::assertTrue($rules->excludes('a/b/c/z.md', false), '"**" crosses folders');
        self::assertTrue($rules->excludes('note.txt', false));
        self::assertFalse($rules->excludes('notes.txt', false));
        self::assertTrue($rules->excludes('../escape.php', false), 'a path that climbs out is never packaged');
    }

    public function testReIncludingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        new DistIgnore(['/docs/', '!/docs/GUIDE.md']);
    }

    public function testTheFilesAreListedSortedWithoutTheExcludedOnes(): void
    {
        $this->dir = TempTree::make([
            'main.php' => '<?php',
            'src/B.php' => '<?php',
            'src/A.php' => '<?php',
            'tests/T.php' => '<?php',
            '.git/config' => 'x',
            'src/.env' => 'x',
            'docs/PLANO.md' => 'x',
            'docs/GUIDE.md' => 'x',
        ]);

        self::assertSame(['docs/GUIDE.md', 'main.php', 'src/A.php', 'src/B.php'], (new DistIgnore(['/tests/', '/docs/PLANO.md']))->files($this->dir));
    }

    public function testASymbolicLinkRefusesTheBuild(): void
    {
        $this->dir = TempTree::make(['src/A.php' => '<?php']);
        symlink($this->dir . '/src/A.php', $this->dir . '/src/Link.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');
        (new DistIgnore([]))->files($this->dir);
    }
}
