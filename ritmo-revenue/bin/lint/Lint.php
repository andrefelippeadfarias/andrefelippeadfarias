<?php

declare(strict_types=1);

namespace Ritmo\Tools\Lint;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The plugin's own lint (WPCS cannot be downloaded here). Each rule returns the problems it finds
 * as "file:line message"; an empty list means the rule passes.
 *
 * 1. Every PHP file parses (php -l).
 * 2. Every value a template prints is one call of an escaping function.
 * 3. No template reads the request ($_GET, $_POST, $_REQUEST, $_COOKIE, $_FILES, $_SERVER).
 * 4. Every REST route has a permission_callback, and none is __return_true.
 * 5. Every form action is registered by the one dispatcher, which checks POST, the capability and
 *    the nonce before the handler; every action names a capability of the plugin.
 * 6. Nothing is loaded from another site: no external URL in an enqueued file, a <script> or
 *    <link> tag, or a stylesheet or script of assets/.
 */
final class Lint
{
    /** What a template may print: exactly one call of one of these. */
    private const ESCAPERS = ['esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'wp_kses', 'wp_kses_post'];

    private const SUPERGLOBALS = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$_SERVER'];

    private const PHP_FOLDERS = ['src', 'templates', 'bin', 'tests', 'languages'];

    private const PHP_FILES = ['ritmo-revenue.php', 'uninstall.php'];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return array<string, list<string>> rule => problems
     */
    public function run(): array
    {
        return [
            'php -l' => $this->syntax(),
            'escaped output' => $this->templates(fn (string $code): array => self::unescapedOutput($code)),
            'no request in templates' => $this->templates(fn (string $code): array => self::requestReads($code)),
            'REST permission' => $this->sources(fn (string $code): array => self::restPermissions($code)),
            'form actions' => $this->formActions(),
            'no external loads' => $this->externalLoads(),
        ];
    }

    /**
     * @return list<array{int, string}> line and statement of every echo/print that is not one escaping call
     */
    public static function unescapedOutput(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
                if (is_array($token) && $token[0] === T_STRING && $token[1] === '_e' && self::isCall($tokens, $index)) {
                    $out[] = [$token[2], '_e() prints without escaping'];
                }
                continue;
            }
            $statement = self::statement($tokens, $index);
            if (!self::isOneEscapingCall($statement)) {
                $out[] = [$token[2], $statement];
            }
        }

        return $out;
    }

    /**
     * @return list<array{int, string}>
     */
    public static function requestReads(string $code): array
    {
        $out = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], self::SUPERGLOBALS, true)) {
                $out[] = [$token[2], $token[1] . ' read in a template'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{int, string}>
     */
    public static function restPermissions(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'register_rest_route' || !self::isCall($tokens, $index)) {
                continue;
            }
            $call = self::callText($tokens, $index);
            if (!str_contains($call, "'permission_callback'")) {
                $out[] = [$token[2], 'register_rest_route() without a permission_callback'];
            } elseif (str_contains($call, '__return_true')) {
                $out[] = [$token[2], 'a permission_callback of __return_true'];
            }
        }

        return $out;
    }

    /**
     * @return list<string> the URLs that point to another site
     */
    public static function externalUrls(string $text): array
    {
        preg_match_all('#(?:https?:)?//[a-z0-9.-]+\.[a-z]{2,}[^\s"\')]*#i', $text, $m);

        return array_values(array_filter($m[0], static fn (string $url): bool => !str_contains($url, '127.0.0.1') && !str_contains($url, 'localhost')));
    }

    /**
     * @return list<string>
     */
    private function syntax(): array
    {
        $files = [];
        foreach (self::PHP_FOLDERS as $folder) {
            array_push($files, ...$this->files($folder, ['php'], ['tests/fake-apis/data', 'tests/e2e/node_modules']));
        }
        foreach (self::PHP_FILES as $file) {
            if (is_file($this->root . '/' . $file)) {
                $files[] = $file;
            }
        }
        $problems = [];
        foreach (array_chunk($files, 80) as $chunk) {
            $command = escapeshellarg(PHP_BINARY) . ' -l ' . implode(' ', array_map(fn (string $f): string => escapeshellarg($this->root . '/' . $f), $chunk)) . ' 2>&1';
            exec($command, $lines, $status);
            foreach ($lines as $line) {
                if ($line !== '' && !str_starts_with($line, 'No syntax errors detected')) {
                    $problems[] = str_replace($this->root . '/', '', $line);
                }
            }
            $lines = [];
        }

        return $problems;
    }

    /**
     * @param callable(string): list<array{int, string}> $rule
     *
     * @return list<string>
     */
    private function templates(callable $rule): array
    {
        $out = [];
        foreach ($this->files('templates', ['php']) as $file) {
            foreach ($rule((string) file_get_contents($this->root . '/' . $file)) as [$line, $message]) {
                $out[] = $file . ':' . $line . ' ' . $message;
            }
        }

        return $out;
    }

    /**
     * @param callable(string): list<array{int, string}> $rule
     *
     * @return list<string>
     */
    private function sources(callable $rule): array
    {
        $out = [];
        foreach ($this->files('src', ['php']) as $file) {
            foreach ($rule((string) file_get_contents($this->root . '/' . $file)) as [$line, $message]) {
                $out[] = $file . ':' . $line . ' ' . $message;
            }
        }

        return $out;
    }

    /**
     * Form actions: only the dispatcher hooks admin-post.php, it checks POST, the capability and
     * the nonce before the handler, and every action names a capability of the plugin.
     *
     * @return list<string>
     */
    private function formActions(): array
    {
        $out = [];
        $dispatcher = 'src/Admin/Screens/ScreenActions.php';
        foreach ($this->files('src', ['php']) as $file) {
            $code = (string) file_get_contents($this->root . '/' . $file);
            if ($file !== $dispatcher && preg_match("/add_action\\(\\s*['\"]admin_post_/", $code) === 1) {
                $out[] = $file . ' hooks admin-post.php outside the dispatcher';
            }
            preg_match_all('/(\$[a-z]+) = ((?:Capability|Roles)::[A-Z_]+);/', $code, $named);
            $variables = array_combine($named[1], $named[2]);
            foreach (self::formActionCapabilities($code) as [$line, $capability]) {
                $capability = $variables[$capability] ?? $capability;
                if (preg_match('/^(Capability|Roles)::[A-Z_]+$/', $capability) !== 1) {
                    $out[] = $file . ':' . $line . ' a form action without a capability of the plugin (' . $capability . ')';
                }
            }
        }
        $handle = (string) file_get_contents($this->root . '/' . $dispatcher);
        $body = substr($handle, (int) strpos($handle, 'function handle('));
        $order = [strpos($body, "REQUEST_METHOD"), strpos($body, 'current_user_can('), strpos($body, 'check_admin_referer('), strpos($body, '->handler')];
        if (in_array(false, $order, true) || $order !== array_values(array_unique($order)) || $order[0] > $order[1] || $order[1] > $order[2] || $order[2] > $order[3]) {
            $out[] = $dispatcher . ' must check POST, then the capability, then the nonce, before the handler';
        }

        return $out;
    }

    /**
     * The second argument of every "new FormAction(" (its capability), with the line.
     *
     * @return list<array{int, string}>
     */
    private static function formActionCapabilities(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }
            $name = self::nextMeaningful($tokens, $index);
            if ($name === null || !is_array($tokens[$name]) || !in_array($tokens[$name][1], ['FormAction', 'Components\\FormAction'], true)) {
                continue;
            }
            $arguments = self::arguments(self::callText($tokens, $name));
            $out[] = [$token[2], trim($arguments[1] ?? '')];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function externalLoads(): array
    {
        $out = [];
        foreach ($this->files('assets', ['css', 'js']) as $file) {
            foreach (self::externalUrls((string) file_get_contents($this->root . '/' . $file)) as $url) {
                $out[] = $file . ' loads ' . $url;
            }
        }
        foreach ([...$this->files('src', ['php']), ...$this->files('templates', ['php'])] as $file) {
            $code = (string) file_get_contents($this->root . '/' . $file);
            preg_match_all('#wp_(?:enqueue|register)_(?:script|style)\([^;]*;|<script\b[^>]*>|<link\b[^>]*>|@import[^;]*;#i', $code, $m);
            foreach ($m[0] as $load) {
                foreach (self::externalUrls($load) as $url) {
                    $out[] = $file . ' loads ' . $url;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $skip       path prefixes to leave out
     *
     * @return list<string> paths relative to the root, sorted
     */
    private function files(string $folder, array $extensions, array $skip = []): array
    {
        $base = $this->root . '/' . $folder;
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $relative = substr($file->getPathname(), strlen($this->root) + 1);
            if (!in_array($file->getExtension(), $extensions, true)) {
                continue;
            }
            foreach ($skip as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }
            $out[] = $relative;
        }
        sort($out, SORT_STRING);

        return $out;
    }

    private static function isOneEscapingCall(string $statement): bool
    {
        if (preg_match('/^(' . implode('|', self::ESCAPERS) . ')\((.*)\)$/s', $statement, $m) !== 1) {
            return false;
        }
        $depth = 0;
        foreach (str_split((string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $m[2])) as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function statement(array $tokens, int $index): string
    {
        $text = '';
        for ($next = $index + 1; isset($tokens[$next]); ++$next) {
            $part = $tokens[$next];
            if ($part === ';' || (is_array($part) && $part[0] === T_CLOSE_TAG)) {
                break;
            }
            $text .= is_array($part) ? $part[1] : $part;
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function isCall(array $tokens, int $index): bool
    {
        $next = self::nextMeaningful($tokens, $index);
        $previous = $index - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
            --$previous;
        }
        $method = $previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

        return !$method && $next !== null && $tokens[$next] === '(';
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($next = $index + 1; isset($tokens[$next]); ++$next) {
            if (!is_array($tokens[$next]) || !in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $next;
            }
        }

        return null;
    }

    /**
     * The text of a call from its name to its closing parenthesis.
     *
     * @param list<mixed> $tokens
     */
    private static function callText(array $tokens, int $index): string
    {
        $text = '';
        $depth = 0;
        for ($next = $index; isset($tokens[$next]); ++$next) {
            $part = $tokens[$next];
            $value = is_array($part) ? $part[1] : $part;
            $text .= $value;
            if ($part === '(' || $part === '[') {
                ++$depth;
            } elseif ($part === ')' || $part === ']') {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            }
        }

        return $text;
    }

    /**
     * The top-level arguments of a call's text.
     *
     * @return list<string>
     */
    private static function arguments(string $call): array
    {
        $inner = substr($call, (int) strpos($call, '(') + 1, -1);
        $args = [];
        $depth = 0;
        $current = '';
        $quote = null;
        foreach (str_split($inner) as $char) {
            if ($quote !== null) {
                $current .= $char;
                $quote = $char === $quote ? null : $quote;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $args[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $args[] = $current;

        return $args;
    }
}
