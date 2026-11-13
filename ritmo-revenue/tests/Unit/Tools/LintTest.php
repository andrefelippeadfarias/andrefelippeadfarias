<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\Lint\Lint;

require_once dirname(__DIR__, 3) . '/bin/lint/Lint.php';

/**
 * bin/lint.php: every rule catches what it is there for, and the plugin passes all of them.
 */
final class LintTest extends TestCase
{
    public function testThePluginPassesEveryRule(): void
    {
        self::assertSame(array_fill_keys(['php -l', 'escaped output', 'no request in templates', 'REST permission', 'form actions', 'no external loads'], []), (new Lint(Fixtures::pluginRoot()))->run());
    }

    public function testATemplateMayOnlyPrintOneEscapingCall(): void
    {
        self::assertSame([], Lint::unescapedOutput('<?php echo esc_html($a); ?><?= esc_attr($b) ?><?php esc_html_e("x", "d"); ?>'));
        self::assertCount(1, Lint::unescapedOutput('<?php echo $a; ?>'));
        self::assertCount(1, Lint::unescapedOutput('<?= $title ?>'));
        self::assertCount(1, Lint::unescapedOutput('<?php echo esc_html($a) . $b; ?>'));
        self::assertCount(1, Lint::unescapedOutput('<?php print $a; ?>'));
        self::assertCount(1, Lint::unescapedOutput('<?php _e("Raw", "d"); ?>'));
        self::assertSame([], Lint::unescapedOutput('<?php $x->_e("method"); ?>'), 'a method of that name is not the WordPress function');
    }

    public function testATemplateNeverReadsTheRequest(): void
    {
        self::assertCount(2, Lint::requestReads('<?php $a = $_GET["x"]; $b = $_POST["y"]; ?>'));
        self::assertSame([], Lint::requestReads('<?php $get = 1; ?>'));
    }

    public function testEveryRestRouteHasARealPermissionCallback(): void
    {
        self::assertSame([], Lint::restPermissions("<?php register_rest_route('ns', '/a', ['methods' => 'GET', 'callback' => 'f', 'permission_callback' => self::allowed('x')]);"));
        self::assertCount(1, Lint::restPermissions("<?php register_rest_route('ns', '/a', ['methods' => 'GET', 'callback' => 'f']);"));
        self::assertCount(1, Lint::restPermissions("<?php register_rest_route('ns', '/a', ['callback' => 'f', 'permission_callback' => '__return_true']);"));
    }

    public function testAnExternalUrlIsFound(): void
    {
        self::assertSame(['https://cdn.example.com/x.js'], Lint::externalUrls('<script src="https://cdn.example.com/x.js">'));
        self::assertSame(['//fonts.example.org/css'], Lint::externalUrls("@import url(//fonts.example.org/css);"));
        self::assertSame([], Lint::externalUrls('fetch("http://127.0.0.1:8091/x")'));
    }

    public function testABrokenFileFailsTheSyntaxRule(): void
    {
        $root = sys_get_temp_dir() . '/ritmo-lint-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/Broken.php', "<?php\nfunction (\n");
        file_put_contents($root . '/src/Fine.php', "<?php\nreturn 1;\n");
        mkdir($root . '/src/Admin/Screens', 0777, true);
        file_put_contents($root . '/src/Admin/Screens/ScreenActions.php', "<?php function handle() { \$_SERVER['REQUEST_METHOD']; current_user_can(1); check_admin_referer(1); \$a->handler; }");

        $result = (new Lint($root))->run();
        array_map('unlink', [$root . '/src/Broken.php', $root . '/src/Fine.php', $root . '/src/Admin/Screens/ScreenActions.php']);

        self::assertNotSame([], $result['php -l']);
        self::assertSame([], array_filter($result['php -l'], static fn (string $line): bool => !str_contains($line, 'Broken.php')), 'only the broken file is reported');
        self::assertSame([], $result['form actions']);
    }
}
