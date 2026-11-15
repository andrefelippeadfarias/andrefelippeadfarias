<?php

declare(strict_types=1);

namespace Ritmo\Tools\Package;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Finds what PHP 8.1 cannot run in code that a newer PHP parses (the plugin promises PHP 8.1+ and
 * is developed on a newer PHP, where php -l accepts it all). Reads the code with token_get_all();
 * runs nothing. Each problem is "line: message".
 *
 * Syntax: readonly classes (8.2), constants in traits (8.2), DNF types such as (A&B)|null (8.2),
 * null, false and true as types of their own and true in a union (8.2), enum properties (->) in
 * constant expressions (8.2), typed class constants (8.3), dynamic class constant fetch
 * Foo::{$name} (8.3), static variables initialised with an expression (8.3).
 * Library: functions, classes and static methods added in 8.2 and later (a function is allowed
 * when the same file asks function_exists() for it).
 */
final class Php81Lint
{
    /** Functions added after PHP 8.1, lowercase => version. */
    public const FUNCTIONS = [
        'curl_upkeep' => '8.2', 'ini_parse_quantity' => '8.2', 'libxml_get_external_entity_loader' => '8.2',
        'memory_reset_peak_usage' => '8.2', 'mysqli_execute_query' => '8.2', 'openssl_cipher_key_length' => '8.2',
        'sodium_crypto_stream_xchacha20_xor_ic' => '8.2', 'odbc_connection_string_is_quoted' => '8.2',
        'odbc_connection_string_should_quote' => '8.2', 'odbc_connection_string_quote' => '8.2',
        'json_validate' => '8.3', 'mb_str_pad' => '8.3', 'str_increment' => '8.3', 'str_decrement' => '8.3',
        'stream_context_set_options' => '8.3', 'posix_eaccess' => '8.3', 'posix_sysconf' => '8.3',
        'posix_pathconf' => '8.3', 'posix_fpathconf' => '8.3', 'socket_atmark' => '8.3',
        'ldap_connect_wallet' => '8.3', 'ldap_exop_sync' => '8.3',
        'array_find' => '8.4', 'array_find_key' => '8.4', 'array_any' => '8.4', 'array_all' => '8.4',
        'mb_trim' => '8.4', 'mb_ltrim' => '8.4', 'mb_rtrim' => '8.4', 'mb_ucfirst' => '8.4', 'mb_lcfirst' => '8.4',
        'bcround' => '8.4', 'bcfloor' => '8.4', 'bcceil' => '8.4', 'bcdivmod' => '8.4', 'fpow' => '8.4',
        'http_get_last_response_headers' => '8.4', 'http_clear_last_response_headers' => '8.4',
        'request_parse_body' => '8.4', 'grapheme_str_split' => '8.4',
        'array_first' => '8.5', 'array_last' => '8.5', 'get_error_handler' => '8.5', 'get_exception_handler' => '8.5',
    ];

    /** Classes added after PHP 8.1 (fully qualified, lowercase) => version. Attributes are left out: PHP 8.1 ignores unknown ones. */
    public const CLASSES = [
        'random\randomizer' => '8.2', 'random\engine' => '8.2', 'random\cryptosafeengine' => '8.2',
        'random\engine\mt19937' => '8.2', 'random\engine\pcgoneseq128xslrr64' => '8.2',
        'random\engine\xoshiro256starstar' => '8.2', 'random\engine\secure' => '8.2', 'random\randomerror' => '8.2',
        'random\brokenrandomengineerror' => '8.2', 'random\randomexception' => '8.2', 'sensitiveparametervalue' => '8.2',
        'random\intervalboundary' => '8.3', 'dateerror' => '8.3', 'dateobjecterror' => '8.3', 'daterangeerror' => '8.3',
        'dateexception' => '8.3', 'dateinvalidoperationexception' => '8.3', 'dateinvalidtimezoneexception' => '8.3',
        'datemalformedintervalstringexception' => '8.3', 'datemalformedperiodstringexception' => '8.3',
        'datemalformedstringexception' => '8.3', 'sqlite3exception' => '8.3',
        'bcmath\number' => '8.4', 'dom\htmldocument' => '8.4', 'dom\xmldocument' => '8.4', 'pdo\sqlite' => '8.4',
        'pdo\mysql' => '8.4', 'pdo\pgsql' => '8.4', 'pdo\firebird' => '8.4', 'pdo\odbc' => '8.4', 'pdo\dblib' => '8.4',
    ];

    /** Static methods added after PHP 8.1 to classes that already existed, lowercase => version. */
    public const STATIC_METHODS = [
        'reflectionmethod::createfrommethodname' => '8.3',
        'datetime::createfromtimestamp' => '8.4', 'datetimeimmutable::createfromtimestamp' => '8.4', 'pdo::connect' => '8.4',
    ];

    private const MEMBER_MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR, T_FINAL, T_ABSTRACT];

    /** @var list<array{0: int|string, 1: string, 2: int}> significant tokens: id, text, line */
    private array $t = [];

    /** @var array<int, int> index of each "{" => index of its "}" */
    private array $pairs = [];

    /** @var array<int, int> index of each token => index of the "{" it is directly inside (-1: none) */
    private array $enclosing = [];

    /** @var list<array{int, string}> */
    private array $problems = [];

    private function __construct(private readonly string $code)
    {
    }

    /**
     * @return list<string> "line: message", in the order of the code
     */
    public static function check(string $code): array
    {
        $lint = new self($code);
        $lint->tokenize();
        $lint->run();
        usort($lint->problems, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($lint->problems as [$line, $message]) {
            $out[] = $line . ': ' . $message;
        }

        return array_values(array_unique($out));
    }

    /**
     * Checks every PHP file under the given files and folders.
     *
     * @param list<string> $paths
     *
     * @return array<string, list<string>> file (as given, joined with its relative path) => problems; files without problems are left out
     */
    public static function checkPaths(array $paths): array
    {
        $out = [];
        foreach (self::phpFiles($paths) as $file) {
            $problems = self::check((string) file_get_contents($file));
            if ($problems !== []) {
                $out[$file] = $problems;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string> sorted
     */
    public static function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                throw new RuntimeException("Not a file or folder: $path");
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files, SORT_STRING);

        return array_values(array_unique($files));
    }

    private function tokenize(): void
    {
        $line = 1;
        foreach (token_get_all($this->code) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    $this->t[] = [$token[0], $token[1], $token[2]];
                }
                $line += substr_count($token[1], "\n");
                continue;
            }
            $this->t[] = [$token, $token, $line];
        }
        $stack = [];
        foreach ($this->t as $i => $token) {
            $this->enclosing[$i] = $stack === [] ? -1 : $stack[count($stack) - 1];
            if ($token[0] === '{' || $token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stack[] = $i;
            } elseif ($token[0] === '}' && $stack !== []) {
                $this->pairs[array_pop($stack)] = $i;
                $this->enclosing[$i] = $stack === [] ? -1 : $stack[count($stack) - 1];
            }
        }
    }

    private function run(): void
    {
        [$namespace, $imports] = $this->names();
        $guarded = [];
        if (preg_match_all('/function_exists\(\s*[\'"]\\\\?([A-Za-z0-9_]+)[\'"]/', $this->code, $m) > 0) {
            $guarded = array_map('strtolower', $m[1]);
        }
        $traitBodies = [];
        $classBodies = [];
        $count = count($this->t);
        for ($i = 0; $i < $count; ++$i) {
            [$id, $text, $line] = $this->t[$i];
            $prev = $this->t[$i - 1][0] ?? null;
            if ($id === T_ATTRIBUTE) {
                $i = $this->closing($i, '[', ']');
                continue;
            }
            if (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && $prev !== T_DOUBLE_COLON && $prev !== T_OBJECT_OPERATOR && $prev !== T_NULLSAFE_OBJECT_OPERATOR) {
                $open = $this->next($i, '{');
                if ($open !== null) {
                    $classBodies[$open] = true;
                    if ($id === T_TRAIT) {
                        $traitBodies[$open] = true;
                    }
                }
            }
            if ($id === T_READONLY) {
                $j = $i + 1;
                while (in_array($this->t[$j][0] ?? null, [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                    ++$j;
                }
                if (($this->t[$j][0] ?? null) === T_CLASS) {
                    $this->add($line, 'readonly class (PHP 8.2)');
                }
            }
            if ($id === T_CONST && $prev !== T_USE && !$this->inGroupUse($i)) {
                $this->constant($i, isset($traitBodies[$this->enclosing[$i]]));
            }
            if ($id === T_DOUBLE_COLON && ($this->t[$i + 1][0] ?? null) === '{') {
                $this->add($line, 'dynamic class constant fetch ::{} (PHP 8.3)');
            }
            if ($id === T_STATIC && ($this->t[$i + 1][0] ?? null) === T_VARIABLE && ($this->t[$i + 2][0] ?? null) === '=') {
                $end = $this->expressionEnd($i + 3, [';', ',']);
                if ($this->hasDynamicPart($i + 3, $end)) {
                    $this->add($line, 'static variable initialised with an expression (PHP 8.3)');
                }
            }
            if (($id === T_FUNCTION || $id === T_FN) && $prev !== T_USE) {
                $this->signature($i);
            }
            if (in_array($id, self::MEMBER_MODIFIERS, true) && isset($classBodies[$this->enclosing[$i]]) && !in_array($prev, self::MEMBER_MODIFIERS, true)) {
                $this->property($i);
            }
            if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                $this->name($i, $namespace, $imports, $guarded);
            }
        }
    }

    /**
     * The namespace of the file and its class imports (alias, lowercase => fully qualified, lowercase).
     *
     * @return array{string, array<string, string>}
     */
    private function names(): array
    {
        $namespace = '';
        $imports = [];
        $depth = 0;
        foreach ($this->t as $i => [$id, $text]) {
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            } elseif ($id === '}') {
                --$depth;
            }
            if ($id === T_NAMESPACE && in_array($this->t[$i + 1][0] ?? null, [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = strtolower($this->t[$i + 1][1]);
            }
            if ($id !== T_USE || $depth > 0) {
                continue;
            }
            $kind = $this->t[$i + 1][0] ?? null;
            if ($kind === T_FUNCTION || $kind === T_CONST || $kind === '(') {
                continue;
            }
            $j = $i + 1;
            $name = null;
            while (isset($this->t[$j]) && $this->t[$j][0] !== ';' && $this->t[$j][0] !== '{') {
                $tid = $this->t[$j][0];
                if (in_array($tid, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($this->t[$j - 1][0] ?? null) !== T_AS) {
                    $name = strtolower(ltrim($this->t[$j][1], '\\'));
                    $parts = explode('\\', $name);
                    $imports[end($parts)] = $name;
                } elseif ($tid === T_AS && $name !== null && ($this->t[$j + 1][0] ?? null) === T_STRING) {
                    $parts = explode('\\', $name);
                    unset($imports[end($parts)]);
                    $imports[strtolower($this->t[$j + 1][1])] = $name;
                }
                ++$j;
            }
        }

        return [$namespace, $imports];
    }

    /**
     * @param array<string, string> $imports
     * @param list<string>          $guarded functions the file asks function_exists() for
     */
    private function name(int $i, string $namespace, array $imports, array $guarded): void
    {
        [$id, $text, $line] = $this->t[$i];
        $prev = $this->t[$i - 1][0] ?? null;
        $next = $this->t[$i + 1][0] ?? null;
        if (in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_USE, T_NAMESPACE, T_AS, T_GOTO, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_CASE], true)) {
            return;
        }
        $bare = strtolower(ltrim($text, '\\'));
        if ($next === '(' && $prev !== T_NEW && $id !== T_NAME_QUALIFIED) {
            if (isset(self::FUNCTIONS[$bare]) && !str_contains($bare, '\\') && !in_array($bare, $guarded, true)) {
                $this->add($line, sprintf('%s() needs PHP %s', $bare, self::FUNCTIONS[$bare]));
            }

            return;
        }
        $class = match ($id) {
            T_NAME_FULLY_QUALIFIED => $bare,
            T_NAME_QUALIFIED => isset($imports[strtolower(strstr($text, '\\', true))]) ? $imports[strtolower(strstr($text, '\\', true))] . strtolower(strstr($text, '\\')) : ($namespace === '' ? $bare : $namespace . '\\' . $bare),
            default => $imports[$bare] ?? ($namespace === '' ? $bare : $namespace . '\\' . $bare),
        };
        if (isset(self::CLASSES[$class])) {
            $this->add($line, sprintf('class %s needs PHP %s', $class, self::CLASSES[$class]));
        }
        if ($next === T_DOUBLE_COLON && ($this->t[$i + 2][0] ?? null) === T_STRING && ($this->t[$i + 3][0] ?? null) === '(') {
            $method = $class . '::' . strtolower($this->t[$i + 2][1]);
            if (isset(self::STATIC_METHODS[$method])) {
                $this->add($line, sprintf('%s() needs PHP %s', $method, self::STATIC_METHODS[$method]));
            }
        }
    }

    private function constant(int $i, bool $inTrait): void
    {
        $line = $this->t[$i][2];
        if ($inTrait) {
            $this->add($line, 'constant in a trait (PHP 8.2)');
        }
        $j = $i + 1;
        $before = 0;
        while (isset($this->t[$j]) && !in_array($this->t[$j][0], ['=', ';', ',', '}'], true)) {
            ++$before;
            ++$j;
        }
        if (($this->t[$j][0] ?? null) !== '=') {
            return;
        }
        if ($before > 1) {
            $this->add($line, 'typed class constant (PHP 8.3)');
        }
        $end = $this->expressionEnd($j + 1, [';']);
        if ($this->has($j + 1, $end, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $this->add($line, 'property fetch (->) in a constant expression (PHP 8.2)');
        }
    }

    /**
     * The parameters and the return type of a function, a method, a closure or an arrow function.
     */
    private function signature(int $i): void
    {
        $open = $this->next($i, '(', 4);
        if ($open === null) {
            return;
        }
        $close = $this->closing($open, '(', ')');
        $start = $open + 1;
        $depth = 0;
        for ($j = $open + 1; $j <= $close; ++$j) {
            $id = $this->t[$j][0];
            if ($id === T_ATTRIBUTE) {
                $j = $this->closing($j, '[', ']');
                $start = $j + 1;
                continue;
            }
            if ($id === '(' || $id === '[' || $id === '{') {
                ++$depth;
            } elseif (($id === ')' || $id === ']' || $id === '}') && $j < $close) {
                --$depth;
            }
            if ($depth === 0 && ($id === ',' || $j === $close)) {
                $this->parameter($start, $j);
                $start = $j + 1;
            }
        }
        $after = $close + 1;
        if (($this->t[$after][0] ?? null) === T_USE) {
            $useOpen = $this->next($after, '(', 1);
            $after = $useOpen === null ? $after : $this->closing($useOpen, '(', ')') + 1;
        }
        if (($this->t[$after][0] ?? null) === ':') {
            $end = $after + 1;
            while (isset($this->t[$end]) && !in_array($this->t[$end][0], ['{', ';', T_DOUBLE_ARROW], true)) {
                ++$end;
            }
            $this->type($after + 1, $end);
        }
    }

    /**
     * One parameter, tokens [start, end): attributes are already skipped.
     */
    private function parameter(int $start, int $end): void
    {
        $j = $start;
        while ($j < $end && in_array($this->t[$j][0], self::MEMBER_MODIFIERS, true)) {
            ++$j;
        }
        $variable = $j;
        while ($variable < $end && !in_array($this->t[$variable][0], [T_VARIABLE, T_ELLIPSIS, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
            ++$variable;
        }
        $this->type($j, $variable);
        for ($k = $variable; $k < $end; ++$k) {
            if ($this->t[$k][0] === '=') {
                if ($this->has($k + 1, $end, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                    $this->add($this->t[$k][2], 'property fetch (->) in a default value (PHP 8.2)');
                }
                break;
            }
        }
    }

    /**
     * A property declaration that starts at a modifier inside a class body.
     */
    private function property(int $i): void
    {
        $j = $i;
        while (in_array($this->t[$j][0] ?? null, self::MEMBER_MODIFIERS, true)) {
            ++$j;
        }
        $variable = $j;
        while (isset($this->t[$variable]) && !in_array($this->t[$variable][0], [T_VARIABLE, T_FUNCTION, T_CONST, T_FN, ';', '{', '='], true)) {
            ++$variable;
        }
        if (($this->t[$variable][0] ?? null) !== T_VARIABLE) {
            return;
        }
        $this->type($j, $variable);
        if (($this->t[$variable + 1][0] ?? null) === '=') {
            $end = $this->expressionEnd($variable + 2, [';', ',']);
            if ($this->has($variable + 2, $end, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                $this->add($this->t[$variable][2], 'property fetch (->) in a default value (PHP 8.2)');
            }
        }
    }

    /**
     * A type, tokens [start, end).
     */
    private function type(int $start, int $end): void
    {
        if ($start >= $end) {
            return;
        }
        $line = $this->t[$start][2];
        $words = [];
        for ($j = $start; $j < $end; ++$j) {
            $id = $this->t[$j][0];
            if ($id === '(') {
                $this->add($line, 'DNF type (PHP 8.2)');

                return;
            }
            if ($id === T_STRING || $id === T_ARRAY || $id === T_STATIC || $id === T_CALLABLE) {
                $words[] = strtolower($this->t[$j][1]);
            }
        }
        if (count($words) === 1 && in_array($words[0], ['null', 'false', 'true'], true) && $end - $start === 1) {
            $this->add($line, sprintf('%s as a type of its own (PHP 8.2)', $words[0]));
        } elseif (in_array('true', $words, true)) {
            $this->add($line, 'true in a union type (PHP 8.2)');
        }
    }

    private function hasDynamicPart(int $start, int $end): bool
    {
        for ($j = $start; $j < $end; ++$j) {
            $id = $this->t[$j][0];
            $prev = $this->t[$j - 1][0] ?? null;
            if ($id === T_VARIABLE || $id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
                return true;
            }
            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true) && ($this->t[$j + 1][0] ?? null) === '(' && $prev !== T_NEW) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int|string> $ids
     */
    private function has(int $start, int $end, array $ids): bool
    {
        for ($j = $start; $j < $end; ++$j) {
            if (in_array($this->t[$j][0], $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index of the first token at depth 0 that is one of $stops (or the end of the code).
     *
     * @param list<string> $stops
     */
    private function expressionEnd(int $start, array $stops): int
    {
        $depth = 0;
        $count = count($this->t);
        for ($j = $start; $j < $count; ++$j) {
            $id = $this->t[$j][0];
            if ($id === '(' || $id === '[' || $id === '{' || $id === T_ATTRIBUTE) {
                ++$depth;
            } elseif ($id === ')' || $id === ']' || $id === '}') {
                if ($depth === 0) {
                    return $j;
                }
                --$depth;
            } elseif ($depth === 0 && in_array($id, $stops, true)) {
                return $j;
            }
        }

        return $count;
    }

    /**
     * The first token $id after $i, before the next ";" and looking at most $within tokens ahead
     * (0: no limit).
     */
    private function next(int $i, string $id, int $within = 0): ?int
    {
        $count = count($this->t);
        for ($j = $i + 1; $j < $count && ($within === 0 || $j <= $i + $within); ++$j) {
            if ($this->t[$j][0] === $id) {
                return $j;
            }
            if ($this->t[$j][0] === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * The index of the bracket that closes the one at $i (T_ATTRIBUTE counts as an opening "[").
     */
    private function closing(int $i, string $open, string $close): int
    {
        $depth = 0;
        $count = count($this->t);
        for ($j = $i; $j < $count; ++$j) {
            $id = $this->t[$j][0];
            if ($id === $open || ($open === '[' && $id === T_ATTRIBUTE)) {
                ++$depth;
            } elseif ($id === $close) {
                --$depth;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $count - 1;
    }

    private function inGroupUse(int $i): bool
    {
        for ($j = $i - 1; $j >= 0 && $j >= $i - 64; --$j) {
            $id = $this->t[$j][0];
            if ($id === ';' || $id === '}') {
                return false;
            }
            if ($id === '{') {
                return ($this->t[$j - 1][0] ?? null) === T_NS_SEPARATOR || in_array($this->t[$j - 1][0] ?? null, [T_NAME_QUALIFIED, T_STRING], true) && $this->useBefore($j);
            }
        }

        return false;
    }

    private function useBefore(int $j): bool
    {
        for ($k = $j - 1; $k >= 0 && $k >= $j - 8; --$k) {
            if ($this->t[$k][0] === T_USE) {
                return true;
            }
            if ($this->t[$k][0] === ';' || $this->t[$k][0] === '{' || $this->t[$k][0] === '}') {
                return false;
            }
        }

        return false;
    }


    private function add(int $line, string $message): void
    {
        $this->problems[] = [$line, $message];
    }
}
