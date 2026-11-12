<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Admin\AdminActions;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;
use Ritmo\WordPress\Roles;

/**
 * Rules of the WordPress-facing code that hold by construction and are checked on the source:
 * escaped output, protected routes and actions, and use cases that never reach WordPress.
 */
final class WordPressLayerRulesTest extends TestCase
{
    public function testEveryValuePrintedByATemplateGoesThroughAnEscapingFunction(): void
    {
        $files = SourceScan::phpFiles(Fixtures::pluginRoot() . '/templates');
        self::assertGreaterThanOrEqual(6, count($files));

        $unescaped = [];
        foreach ($files as $file) {
            foreach (self::echoStatements((string) file_get_contents($file)) as [$line, $statement]) {
                if (preg_match('/^esc_(html|attr|url)\((.*)\)$/s', $statement, $match) !== 1 || !self::balanced($match[2])) {
                    $unescaped[] = basename($file) . ':' . $line . ' echo ' . $statement;
                }
            }
        }

        self::assertSame([], $unescaped, 'a template prints something without escaping it');
        self::assertSame([[1, 'esc_html($a)']], self::echoStatements('<?php echo esc_html($a);'));
        self::assertFalse(self::balanced('$a) . esc_html($b'), 'two calls glued together are not one escaped value');
    }

    public function testEveryRestRouteChecksACapabilityAndTheNonce(): void
    {
        $source = (string) file_get_contents(Fixtures::pluginRoot() . '/src/Rest/RestRoutes.php');

        $routes = substr_count($source, 'register_rest_route(');
        self::assertSame(4, $routes);
        self::assertSame($routes, preg_match_all("/'permission_callback' => self::allowed\\(Roles::[A-Z_]+\\)/", $source));
        self::assertStringContainsString("wp_verify_nonce((string) \$request->get_header('X-WP-Nonce'), 'wp_rest')", $source);
        self::assertStringNotContainsString('__return_true', $source);
    }

    public function testEveryAdminActionChecksItsCapabilityAndNonceBeforeReadingTheForm(): void
    {
        $actions = (new ReflectionClass(AdminActions::class))->getConstant('ACTIONS');
        self::assertSame(AdminActions::names(), array_keys($actions));
        foreach ($actions as $action => [$method, $capability]) {
            self::assertStringStartsWith('ritmo_', $action);
            self::assertTrue(method_exists(AdminActions::class, $method), $method);
            self::assertContains($capability, Roles::CAPABILITIES, $action);
        }

        $source = (string) file_get_contents(Fixtures::pluginRoot() . '/src/Admin/AdminActions.php');
        $handle = substr($source, (int) strpos($source, 'private static function handle('));
        self::assertLessThan(strpos($handle, '$method()'), strpos($handle, 'check_admin_referer($action)'));
        self::assertLessThan(strpos($handle, 'check_admin_referer($action)'), strpos($handle, 'current_user_can($capability)'));
        foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/templates') as $file) {
            $template = (string) file_get_contents($file);
            self::assertSame(substr_count($template, 'name="action"'), substr_count($template, 'wp_nonce_field('), basename($file) . ': every form carries a nonce');
        }
    }

    public function testTheUseCasesNeverReachWordPress(): void
    {
        $files = SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/Application');
        self::assertGreaterThan(15, count($files));
        foreach ($files as $file) {
            self::assertSame([], SourceScan::wordPressMentions((string) file_get_contents($file)), basename($file));
        }
    }

    public function testNoCodePathCallsAWriteMethodOfAConnector(): void
    {
        $writes = ['writeCalendar', 'writeOverrides', 'deleteOverrides', 'updateListing', 'requestRecalculation'];
        foreach (['Application', 'Admin', 'Rest', 'WordPress'] as $folder) {
            foreach (SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/' . $folder) as $file) {
                $source = (string) file_get_contents($file);
                foreach ($writes as $write) {
                    self::assertStringNotContainsString('->' . $write . '(', $source, basename($file) . ' calls ' . $write);
                }
            }
        }
    }

    /**
     * The echo statements of a template: line and what is printed, without the trailing ";".
     *
     * @return list<array{int, string}>
     */
    private static function echoStatements(string $code): array
    {
        $tokens = token_get_all($code);
        $statements = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_ECHO) {
                continue;
            }
            $text = '';
            for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                $part = $tokens[$next];
                if ($part === ';' || (is_array($part) && $part[0] === T_CLOSE_TAG)) {
                    break;
                }
                $text .= is_array($part) ? $part[1] : $part;
            }
            $statements[] = [$token[2], trim((string) preg_replace('/\s+/', ' ', $text))];
        }

        return $statements;
    }

    /**
     * True when the parentheses of the text are balanced from start to end (one call's arguments).
     */
    private static function balanced(string $text): bool
    {
        $depth = 0;
        foreach (str_split((string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $text)) as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }
}
