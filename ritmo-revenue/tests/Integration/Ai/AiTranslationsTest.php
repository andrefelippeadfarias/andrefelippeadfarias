<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Ai;

require_once dirname(__DIR__, 3) . '/bin/i18n/Po.php';
require_once dirname(__DIR__, 3) . '/bin/i18n/PotExtractor.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\I18n\Po;
use Ritmo\Tools\I18n\PotExtractor;

/**
 * Every text of the assistant's screens, routes and templates has its Portuguese translation in
 * languages/ritmo-revenue-pt_BR.po (the texts of M5 were merged into it by the integration), with
 * the same placeholders, the glossary's words and none of its forbidden ones.
 */
final class AiTranslationsTest extends TestCase
{
    private const FOLDERS = ['src/Admin/Ai', 'src/Rest/Ai', 'templates/admin/ai'];

    public function testEveryTextOfTheAssistantIsTranslated(): void
    {
        $root = Fixtures::pluginRoot();
        $translations = [];
        foreach (Po::parse((string) file_get_contents($root . '/languages/ritmo-revenue-pt_BR.po')) as $entry) {
            if ($entry['singular'] !== '') {
                $translations[$entry['singular']] = $entry['translations'];
            }
        }
        $glossary = json_decode((string) file_get_contents($root . '/languages/glossary-pt_BR.json'), true);
        $problems = [];
        $texts = self::texts($root);
        self::assertGreaterThan(150, count($texts));
        foreach ($texts as $text) {
            $source = $text['singular'];
            $target = $translations[$source] ?? null;
            if ($target === null || in_array('', $target, true)) {
                $problems[] = 'untranslated: ' . $source;
                continue;
            }
            if (self::placeholders($source) !== self::placeholders($target[0])) {
                $problems[] = 'placeholders: ' . $source;
            }
            foreach ($glossary['terms'] as $term) {
                if (preg_match('/\b' . preg_quote($term['en'], '/') . 's?\b/i', $source) === 1 && array_filter($term['pt'], static fn (string $pt): bool => mb_stripos(implode(' ', $target), $pt) !== false) === []) {
                    $problems[] = sprintf('"%s" should say %s', $source, implode(' or ', $term['pt']));
                }
            }
            foreach ($glossary['forbidden_anywhere'] as $word) {
                if (mb_stripos(implode(' ', $target), $word) !== false) {
                    $problems[] = sprintf('"%s" uses the forbidden "%s"', $source, $word);
                }
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * The texts of the assistant's files, read by the plugin's own extractor from a copy of them.
     *
     * @return list<array{context: ?string, singular: string, plural: ?string}>
     */
    private static function texts(string $root): array
    {
        $copy = sys_get_temp_dir() . '/ritmo-ai-texts-' . getmypid();
        self::copy($root . '/src/Domain', $copy . '/src/Domain');
        copy($root . '/src/autoload.php', $copy . '/src/autoload.php');
        foreach (self::FOLDERS as $folder) {
            self::copy($root . '/' . $folder, $copy . '/' . $folder);
        }
        try {
            $extractor = new PotExtractor($copy, 'ritmo-revenue');
            self::assertSame([], $extractor->warnings(), 'every text is literal');
            $schema = [];
            foreach (\Ritmo\Domain\Config\Schema::ruleSet()->fields() as $field) {
                $schema[$field->label] = true;
                $schema[$field->help] = true;
            }

            return array_values(array_filter($extractor->texts(), static fn (array $t): bool => !isset($schema[$t['singular']])));
        } finally {
            self::remove($copy);
        }
    }

    private static function copy(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . substr($item->getPathname(), strlen($from));
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%(\d+\$)?[sdf%]/', $text, $m);
        sort($m[0]);

        return $m[0];
    }
}
