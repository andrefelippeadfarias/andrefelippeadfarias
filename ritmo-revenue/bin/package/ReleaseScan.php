<?php

declare(strict_types=1);

namespace Ritmo\Tools\Package;

use RuntimeException;

/**
 * The last look at the package before it is zipped: nothing of the owner's own properties (the
 * terms of tests/fixtures/owner-terms.json, matched as whole words like the architecture test),
 * no key, token, password or private key, no test credential, and only file types a plugin ships.
 * Every problem is "path[:line]: what".
 */
final class ReleaseScan
{
    /** File types the package may hold. */
    public const EXTENSIONS = ['php', 'js', 'css', 'json', 'md', 'txt', 'mo', 'po', 'pot', 'svg', 'png', 'jpg', 'gif', 'webp'];

    /** Files whose content is read (the images are not text). */
    private const TEXT = ['php', 'js', 'css', 'json', 'md', 'txt', 'mo', 'po', 'pot', 'svg'];

    /** Names that never belong in a package, whatever their type. */
    private const FORBIDDEN_NAMES = '/^(wp-config\.php|\.env.*|debug\.log|error_log|id_[a-z0-9]+|.*\.(sqlite3?|db|log|phar|zip|tar|gz|key|pem|p12|pfx|bak|orig|rej|swp|tmp))$|~$/i';

    /** label => pattern: credentials of a known shape. */
    private const SECRETS = [
        'an Anthropic API key' => '/sk-ant-[A-Za-z0-9_\-]{8,}/',
        'an API key of the sk- form' => '/\bsk-(?:proj-)?[A-Za-z0-9]{32,}\b/',
        'an AWS access key' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'a GitHub token' => '/\b(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,})\b/',
        'a Slack token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        'a Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'a private key' => '/-----BEGIN (?:[A-Z]+ )*PRIVATE KEY-----/',
        'a JSON web token' => '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
        'a licence key of the test provider' => '/\bTEST-(?:VALID|EXPIRED|DOWN)-[A-Za-z0-9]/',
        'a credential of the local simulator' => '/\bsim-[a-z0-9-]*(?:token|key|secret|password)[a-z0-9-]*\b|e2e-only/',
    ];

    /** A word that names a secret, then a quoted value that looks random. */
    private const ASSIGNED = '/(api[_-]?key|secret|token|passw(?:or)?d|refresh|licen[cs]e[_-]?key|signing[_-]?key|private[_-]?key|bearer|authorization)["\']?\s*(?:=>|=|:|,)\s*["\']([A-Za-z0-9+\/=_\-.]{20,})["\']/i';

    private readonly string $ownerPattern;

    /**
     * @param list<string> $ownerTerms names, room codes and ids of the owner's own properties
     */
    public function __construct(array $ownerTerms)
    {
        $terms = array_values(array_filter(array_map('trim', $ownerTerms), static fn (string $t): bool => $t !== ''));
        if ($terms === []) {
            throw new RuntimeException('The list of the owner\'s terms is empty: the package cannot be checked.');
        }
        // As in ArchitectureTest: whole words, "_" separates words, any case.
        $this->ownerPattern = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $terms)) . ')(?![\p{L}\p{N}])/iu';
    }

    public static function fromOwnerTermsFile(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException("$file is missing: the build needs the owner's terms to check the package.");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['terms']) || !is_array($data['terms'])) {
            throw new RuntimeException("$file is not a list of terms.");
        }

        return new self(array_map('strval', $data['terms']));
    }

    /**
     * @return list<string> the owner's terms found (as written in the text)
     */
    public function ownerTerms(string $text): array
    {
        // A binary .mo or an invalid UTF-8 text is read byte by byte, without the Unicode classes.
        $pattern = preg_match('//u', $text) === 1 ? $this->ownerPattern : str_replace(['\p{L}\p{N}', '/iu'], ['A-Za-z0-9\x80-\xff', '/i'], $this->ownerPattern);
        if (preg_match_all($pattern, $text, $m) === 0) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /**
     * @return list<string> "line: what" for every credential found
     */
    public static function secrets(string $text): array
    {
        $out = [];
        foreach (self::SECRETS as $label => $pattern) {
            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [, $offset]) {
                    $out[] = self::lineAt($text, $offset) . ': ' . $label;
                }
            }
        }
        if (preg_match_all(self::ASSIGNED, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                if (self::looksRandom($match[2][0])) {
                    $out[] = self::lineAt($text, $match[2][1]) . ': a value that looks like a secret after "' . $match[1][0] . '"';
                }
            }
        }
        $out = array_values(array_unique($out));
        usort($out, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $out;
    }

    /**
     * A credential is long, mixes letters and digits and varies a lot from character to character.
     */
    public static function looksRandom(string $value): bool
    {
        if (strlen($value) < 20 || preg_match_all('/[0-9]/', $value) < 3 || preg_match_all('/[A-Za-z]/', $value) < 3) {
            return false;
        }

        return self::entropy($value) >= 3.5;
    }

    /**
     * Shannon entropy in bits per character.
     */
    public static function entropy(string $value): float
    {
        $length = strlen($value);
        if ($length === 0) {
            return 0.0;
        }
        $bits = 0.0;
        foreach (count_chars($value, 1) as $count) {
            $p = $count / $length;
            $bits -= $p * log($p, 2);
        }

        return $bits;
    }

    /**
     * The problem of a file name, or null.
     */
    public static function fileProblem(string $relative): ?string
    {
        $name = basename($relative);
        if (preg_match(self::FORBIDDEN_NAMES, $name) === 1) {
            return 'a file that never belongs in a package';
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            return 'a file type the package does not ship (' . ($extension === '' ? 'no extension' : '.' . $extension) . ')';
        }

        return null;
    }

    /**
     * Every problem of the files under $dir.
     *
     * @param list<string> $files relative to $dir
     *
     * @return list<string>
     */
    public function scan(string $dir, array $files): array
    {
        $out = [];
        foreach ($files as $relative) {
            $problem = self::fileProblem($relative);
            if ($problem !== null) {
                $out[] = "$relative: $problem";
                continue;
            }
            if (!in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::TEXT, true)) {
                continue;
            }
            $text = (string) file_get_contents($dir . '/' . $relative);
            $terms = $this->ownerTerms($text);
            if ($terms !== []) {
                // The terms themselves are not printed: the build log may be shared.
                $out[] = "$relative: " . count($terms) . " term(s) of the owner's own properties (see tests/fixtures/owner-terms.json)";
            }
            foreach (self::secrets($text) as $secret) {
                $out[] = "$relative:$secret";
            }
        }

        return $out;
    }

    private static function lineAt(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, $offset) + 1;
    }
}
