<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tools\I18n\Compiler;
use Ritmo\Tools\I18n\Po;
use Ritmo\Tools\I18n\PotExtractor;

require_once dirname(__DIR__, 3) . '/bin/i18n/Po.php';
require_once dirname(__DIR__, 3) . '/bin/i18n/PotExtractor.php';
require_once dirname(__DIR__, 3) . '/bin/i18n/Compiler.php';

/**
 * The translation files (spec 05, section 7): the .pot is what the code gives, the pt_BR .po
 * translates every text of it with the same placeholders and the words of the glossary, and the
 * .mo and .l10n.php are what the .po compiles to.
 */
final class TranslationFilesTest extends TestCase
{
    public function testThePotIsWhatTheCodeGivesAndEveryTextIsLiteral(): void
    {
        $extractor = new PotExtractor(Fixtures::pluginRoot(), 'ritmo-revenue');

        self::assertSame([], $extractor->warnings(), 'a translation call with a text that is not literal');
        self::assertGreaterThan(2000, $extractor->count());
        self::assertSame($extractor->pot(), (string) file_get_contents(self::file('ritmo-revenue.pot')), 'languages/ritmo-revenue.pot is stale: run php bin/make-pot.php');
    }

    public function testEveryTextIsTranslatedWithItsPlaceholders(): void
    {
        $pot = self::keyed(Po::parse((string) file_get_contents(self::file('ritmo-revenue.pot'))));
        $po = self::keyed(self::po());

        self::assertSame(array_keys($pot), array_keys($po), 'the .po has exactly the texts of the .pot');
        foreach ($po as $key => $entry) {
            self::assertFalse($entry['fuzzy'], "fuzzy: $key");
            $sources = $entry['plural'] === null ? [$entry['singular']] : [$entry['singular'], $entry['plural']];
            self::assertCount(count($sources), $entry['translations'], "forms of $key");
            foreach ($entry['translations'] as $i => $translation) {
                self::assertNotSame('', $translation, "untranslated: $key");
                self::assertSame(self::placeholders($sources[$i]), self::placeholders($translation), "placeholders of $key");
            }
        }
    }

    public function testTheGlossaryWordsAreUsed(): void
    {
        $glossary = json_decode((string) file_get_contents(self::file('glossary-pt_BR.json')), true);
        $problems = [];
        foreach (self::po() as $entry) {
            $source = $entry['singular'] . ' ' . ($entry['plural'] ?? '');
            $target = implode(' ', $entry['translations']);
            foreach ($glossary['terms'] as $term) {
                if (preg_match('/\b' . preg_quote($term['en'], '/') . 's?\b/i', $source) !== 1) {
                    continue;
                }
                $found = array_filter($term['pt'], static fn (string $pt): bool => mb_stripos($target, $pt) !== false);
                if ($found === []) {
                    $problems[] = sprintf('"%s" should say %s', $entry['singular'], implode(' or ', $term['pt']));
                }
            }
            foreach ($glossary['forbidden_anywhere'] as $word) {
                if (mb_stripos($target, $word) !== false) {
                    $problems[] = sprintf('"%s" uses the forbidden "%s"', $entry['singular'], $word);
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testTheCompiledFilesAreWhatThePoGives(): void
    {
        $compiler = new Compiler(Po::parse((string) file_get_contents(self::file('ritmo-revenue-pt_BR.po'))));

        self::assertTrue($compiler->mo() === file_get_contents(self::file('ritmo-revenue-pt_BR.mo')), 'the .mo is stale: run php bin/compile-l10n.php');
        self::assertTrue($compiler->php() === file_get_contents(self::file('ritmo-revenue-pt_BR.l10n.php')), 'the .l10n.php is stale: run php bin/compile-l10n.php');
        $php = include self::file('ritmo-revenue-pt_BR.l10n.php');
        self::assertSame('nplurals=2; plural=(n > 1);', $php['plural-forms']);
        self::assertSame('Rodar agora', $php['messages']['Run now']);
        self::assertSame("%s noite\0%s noites", $php['messages']["%s night\0%s nights"]);
    }

    public function testTheMoFileReadsBackAsGettextDoes(): void
    {
        $mo = (string) file_get_contents(self::file('ritmo-revenue-pt_BR.mo'));
        $header = unpack('Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', $mo);
        self::assertSame(0x950412de, $header['magic']);
        $messages = [];
        for ($i = 0; $i < $header['count']; ++$i) {
            $o = unpack('Vlength/Voffset', $mo, $header['originals'] + 8 * $i);
            $t = unpack('Vlength/Voffset', $mo, $header['translations'] + 8 * $i);
            $messages[substr($mo, $o['offset'], $o['length'])] = substr($mo, $t['offset'], $t['length']);
        }
        $keys = array_keys($messages);
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $keys, 'gettext needs the originals sorted');
        self::assertSame('Chave de emergência ligada', $messages['Emergency switch engaged']);
        self::assertStringContainsString('Plural-Forms: nplurals=2; plural=(n > 1);', $messages['']);
    }

    /**
     * @return list<array{context: ?string, singular: string, plural: ?string, translations: list<string>, fuzzy: bool, comments: list<string>}>
     */
    private static function po(): array
    {
        return array_values(array_filter(Po::parse((string) file_get_contents(self::file('ritmo-revenue-pt_BR.po'))), static fn (array $e): bool => $e['singular'] !== ''));
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry['singular'] !== '') {
                $out[($entry['context'] ?? '') . "\4" . $entry['singular']] = $entry;
            }
        }
        ksort($out, SORT_STRING);

        return $out;
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

    private static function file(string $name): string
    {
        return Fixtures::pluginRoot() . '/languages/' . $name;
    }
}
