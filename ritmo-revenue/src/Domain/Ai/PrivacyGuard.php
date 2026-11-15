<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * Keeps guest data out of everything the assistant sends and keeps.
 *
 * - scrub(): drops every key of a data tree that names guest data (a name, a contact, a
 *   document, an address, a note or a free-text message) before a prompt is built;
 * - problem() and assertSendable(): refuse a text that looks like an e-mail address, a phone
 *   number, a Brazilian identity document (CPF, CNPJ) or a person's name (a capitalised name after
 *   "hóspede", "clientes", "Sr.", "guest"..., with a space, a colon or a dash between them; or two
 *   capitalised words before "pediu", "asked"...). Nothing is sent then: the person is told why.
 *   Dates, amounts, percentages and booking ids (up to nine digits) are not phone numbers.
 * - requestProblem() and assertRequestSendable(): the same for what the person types to the
 *   assistant, plus a common first name followed by a surname ("Maria Souza") or after an article
 *   or a preposition ("pedido da Maria").
 * - The name checks blank saints and places ("São João", "Nossa Senhora Aparecida") as written,
 *   then read the text without accents and with invisible characters as spaces. They spare
 *   nothing else.
 * - masked(): the data sent never carries a name of the property or of a room that reads like a
 *   person ("Suíte Família Luxo", "Chalé Dona Rosa", in any case): it goes as the room's code (the
 *   property as "PROPERTY"), so such a name never blocks the assistant; the person writes the
 *   code. The data is checked with those names read as "Quarto", so a guest's name next to one is
 *   still found.
 *   clearLabel(): a period of the calendar whose name reads like guest data, in any case, is left
 *   out of the data.
 *
 * The name checks are a heuristic: the screen also asks the person never to write a guest's name.
 */
final class PrivacyGuard
{
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const DOCUMENT = 'document';
    public const NAME = 'name';

    /** A word that introduces a person ("hóspede", "clientes", "Sr.", "guest"...). */
    private const TITLE_ANY = '(?<![\p{L}\d])(?i:h[óo]spedes?|clientes?|fam[íi]lia|sra?\.?|srta\.?|senhora?|dona|guests?|customers?|mrs?\.?|ms\.?|miss)';

    /**
     * A capitalised name right after such a word, with a space, a colon or a dash between them
     * ("hóspede Maria", "Hóspede: Maria", "Hóspedes – Pereira").
     */
    private const NAME_AFTER_TITLE = '/' . self::TITLE_ANY . '(?:\s*[:\-–—]\s*|\s+)\p{Lu}\p{Ll}+/u';

    /** Saints, places and holidays that read like a title and a name ("Nossa Senhora Aparecida", "São João"). */
    private const NOT_A_PERSON = '/(?<![\p{L}\d])(?:(?i:nossa)\s+(?i:senhora)(?:\s+(?:d[aoe]s?\s+)?\p{Lu}\p{Ll}+)?|(?:São|Sao|Santa|Santo|Saint|St\.)\s+\p{Lu}\p{Ll}+)/u';

    /** Common first names (Brazilian and English) that are not also usual words, places or months. */
    private const FIRST_NAMES = 'Maria|José|Jose|Ana|João|Joao|Antônio|Antonio|Francisco|Francisca|Carlos|Paulo|Pedro|Lucas|Luiz|Luis|Luís|Marcos|Luciana|Gabriel|Gabriela|Rafael|Rafaela|Daniel|Daniela|Marcelo|Bruno|Bruna|Eduardo|Felipe|Raimundo|Raimunda|Rodrigo|Manoel|Manuel|Mateus|Matheus|André|Andre|Fernando|Fernanda|Fábio|Fabio|Fabiana|Leonardo|Gustavo|Guilherme|Leandro|Tiago|Thiago|Anderson|Ricardo|Márcio|Marcio|Márcia|Marcia|Jorge|Sebastião|Sebastiao|Alexandre|Roberto|Roberta|Edson|Diego|Vitor|Victor|Sérgio|Sergio|Cláudio|Claudio|Cláudia|Claudia|Juliana|Adriana|Patrícia|Patricia|Aline|Sandra|Camila|Amanda|Jéssica|Jessica|Letícia|Leticia|Júlia|Julia|Luana|Mariana|Beatriz|Larissa|Vanessa|Carla|Débora|Debora|Renata|Renato|Simone|Cristiane|Tatiane|Eliane|Raquel|Sônia|Sonia|Rosana|Isabela|Isabella|Carolina|Paula|Priscila|Natália|Natalia|Helena|Laura|Alice|Sofia|Sophia|Valentina|Manuela|Lorena|Heloísa|Heloisa|Cecília|Cecilia|Lívia|Livia|Giovanna|Bianca|Vinícius|Vinicius|Caio|Enzo|Miguel|Arthur|Artur|Davi|Bernardo|Heitor|Samuel|Henrique|Murilo|Igor|Otávio|Otavio|Wagner|Wellington|Rogério|Rogerio|Kelly|Michele|Michelle|Sílvia|Silvia|Regina|Teresa|Tereza|Lúcia|Lucia|Rita|Joana|Josefa|Antônia|Antonia|Marta|Denise|Elaine|Mônica|Monica|Luíza|Luiza|Lara|Yasmin|John|Mary|James|Robert|Michael|William|David|Richard|Joseph|Thomas|George|Peter|Jennifer|Linda|Elizabeth|Susan|Sarah|Karen|Nancy|Lisa|Emma|Olivia|Emily|Anna|Kate';

    /** A first name followed by a surname ("Maria Souza", "Maria da Silva"). */
    private const FIRST_AND_SURNAME = '/(?<![\p{L}\d])(?:' . self::FIRST_NAMES . ')\s+(?:(?:d[aoe]s?|von|van)\s+)?\p{Lu}\p{Ll}+/u';

    /** A first name after an article or a preposition ("pedido da Maria", "a Ana do quarto 3"). */
    private const FIRST_AFTER_ARTICLE = '/(?<![\p{L}\d])(?i:a|o|à|ao|da|do|de|para|pra|com|pela|pelo|the|for|from|with|by|of)\s+(?:' . self::FIRST_NAMES . ')(?![\p{L}\d])/u';

    /** Two capitalised words before a verb a person does ("João Silva pediu"). */
    private const NAME_BEFORE_VERB = '/(?<![\p{L}\d])\p{Lu}\p{Ll}+\s+\p{Lu}\p{Ll}+\s+(?:pediu|pede|quer|queria|reclamou|reservou|ligou|disse|perguntou|cancelou|asked|wants|booked|called|said|complained|cancelled)(?![\p{L}\d])/u';

    /** Fragments of a normalised key (lowercase letters and digits only) that name guest data. */
    private const FORBIDDEN_KEYS = [
        'name', 'guest', 'mail', 'phone', 'mobile', 'whatsapp', 'contact', 'document', 'passport', 'cpf', 'cnpj',
        'taxid', 'address', 'street', 'zip', 'postcode', 'note', 'comment', 'message', 'remark', 'birth', 'firstname',
        'lastname', 'invoicee', 'customer',
    ];

    private const EMAIL_PATTERN = '/[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+/u';

    private const DOCUMENT_PATTERNS = [
        '/(?<!\d)\d{3}\.?\d{3}\.?\d{3}-\d{2}(?!\d)/',
        '/(?<!\d)\d{2}\.?\d{3}\.?\d{3}\/\d{4}-?\d{2}(?!\d)/',
    ];

    /** Values that look like numbers but are not phone numbers: dates, grouped and decimal amounts, percentages. */
    private const NOT_PHONE_PATTERNS = [
        '/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/',
        '/(?<!\d)\d{1,2}\/\d{1,2}\/\d{2,4}(?!\d)/',
        '/(?<!\d)\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?(?!\d)/',
        '/(?<!\d)\d+[.,]\d{1,2}(?!\d)/',
        '/(?<!\d)\d+(?:[.,]\d+)?\s?%/',
    ];

    /** A run of digits with separators (or a "+") from this many digits on is a phone number. */
    private const PHONE_DIGITS = 8;

    /** A bare run of digits from this length on is a phone number (booking ids are shorter). */
    private const BARE_DIGITS = 10;

    private function __construct()
    {
    }

    /**
     * The data tree without any key that names guest data, at any depth.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function scrub(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::namesGuestData($key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return array_is_list($data) ? array_values($clean) : $clean;
    }

    public static function namesGuestData(string $key): bool
    {
        $normal = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
        foreach (self::FORBIDDEN_KEYS as $fragment) {
            if (str_contains($normal, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the text looks like it carries (one of the constants), or null when it may be sent.
     */
    public static function problem(string $text): ?string
    {
        if (preg_match(self::EMAIL_PATTERN, $text) === 1) {
            return self::EMAIL;
        }
        foreach (self::DOCUMENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::DOCUMENT;
            }
        }
        if (self::nameIn($text, [self::NAME_AFTER_TITLE, self::NAME_BEFORE_VERB])) {
            return self::NAME;
        }
        $rest = (string) preg_replace(self::NOT_PHONE_PATTERNS, ' ', $text);
        if (preg_match_all('/\+?\(?\d[\d\s().\/-]*\d/', $rest, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                $digits = strlen((string) preg_replace('/\D/', '', $candidate));
                $separated = preg_match('/[\s().\/+-]/', $candidate) === 1;
                if (($separated && $digits >= self::PHONE_DIGITS) || $digits >= self::BARE_DIGITS) {
                    return self::PHONE;
                }
            }
        }

        return null;
    }

    /**
     * problem() for a text the person typed to the assistant, with the first-name checks too: on the
     * text, and also on the text without the names and codes of the data ($known), so a room's name
     * between an article and a first name ("à hóspede do 101 Ana") never hides it. Every form only
     * adds refusals.
     *
     * @param list<string> $known the labels and codes of the data sent (stripped())
     */
    public static function requestProblem(string $text, array $known = []): ?string
    {
        $problem = self::problem($text);
        if ($problem !== null) {
            return $problem;
        }
        $first = [self::FIRST_AND_SURNAME, self::FIRST_AFTER_ARTICLE];
        if (self::nameIn($text, $first)) {
            return self::NAME;
        }
        $known = array_values(array_filter(array_map('trim', $known), static fn (string $k): bool => $k !== ''));
        if ($known !== []) {
            usort($known, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            if (self::nameIn(str_ireplace($known, ' ', $text), $first)) {
                return self::NAME;
            }
        }

        return null;
    }

    /**
     * The labels and codes in a data tree (values under "label", "code", "room_label" and
     * "room_code", at any depth): what requestProblem() also takes out of the text.
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<string>
     */
    public static function stripped(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['label', 'code', 'room_label', 'room_code'], true) && (is_string($value) || is_int($value))) {
                $out[] = (string) $value;
            } elseif (is_array($value)) {
                array_push($out, ...self::stripped($value));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Whether one of the patterns finds a person's name, in the text as written or without accents
     * and invisible characters, saints and places ("São João") blanked. A text the patterns cannot
     * read is refused, never let through.
     *
     * @param list<string> $patterns
     */
    private static function nameIn(string $text, array $patterns): bool
    {
        // Saints and places are blanked as written (an accent typed apart never makes one); the
        // patterns then read the text both as written and without accents and invisible characters,
        // and a name found in either is a name.
        $written = preg_replace(self::NOT_A_PERSON, ' ', $text);
        if ($written === null) {
            return true;
        }
        foreach ([$written, self::normal($written)] as $people) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $people) !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The text without accents (NFD, combining marks removed) and with invisible characters read as
     * spaces (a soft hyphen inside a word is dropped).
     */
    private static function normal(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = (string) (\Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text);
        }
        // A soft hyphen inside a word ("hós-pede") is dropped; every other invisible character (a
        // zero-width space or joiner, a direction mark) separates words.
        $text = (string) (preg_replace('/\x{00AD}+/u', '', $text) ?? $text);
        $text = (string) (preg_replace('/[\p{Cf}\x{180E}]/u', ' ', $text) ?? $text);

        return (string) (preg_replace('/\p{Mn}+/u', '', $text) ?? $text);
    }

    /**
     * Whether a label typed by a person (a period of the calendar) is clear of guest data, as it is
     * and in title case ("Casamento da família souza", "CASAMENTO DA FAMÍLIA SOUZA").
     */
    public static function clearLabel(string $label): bool
    {
        return !self::readsLikeAPerson($label);
    }

    /**
     * The text in title case with the small words in lower case ("FÉRIAS EM FAMÍLIA DE JULHO" is
     * "Férias em Família de Julho").
     */
    private static function titled(string $text): string
    {
        $titled = mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');

        return (string) (preg_replace_callback('/(?<=\s)(?:De|Da|Do|Das|Dos|E|Em|Na|No|Nas|Nos|A|O|As|Os|Para|Com|Of|The|And|In|On)(?=\s)/u', static fn (array $m): string => mb_strtolower($m[0], 'UTF-8'), $titled) ?? $titled);
    }

    /**
     * The data with every name of the property or of a room that reads like a person (as written or
     * in title case) replaced, in every string, by the room's code (the property's by "PROPERTY").
     * A code that itself reads like a person ("Dona-Rosa") is replaced too, by "ROOM#1", "ROOM#2"...
     * With $as, every such name is replaced by that word instead: the check of the data runs on
     * that form ("Quarto"), so a guest's name written next to a room's is still found. Only text is
     * taken out: nothing reaches the assistant that was not in the data.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function masked(array $data, ?string $as = null): array
    {
        $replace = [];
        $rooms = 0;
        foreach (self::namedThings($data) as [$name, $code]) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));
            $isProperty = $code === null;
            $codeReadsLikeAPerson = !$isProperty && self::readsLikeAPerson(strtr((string) $code, '-_', '  '));
            // ROOM#n can never be a room's code, so a draft can never point it at another room.
            $neutral = $isProperty ? 'PROPERTY' : ($codeReadsLikeAPerson ? 'ROOM#' . (++$rooms) : (string) $code);
            if ($name !== '' && self::readsLikeAPerson($name)) {
                $replace[] = [$name, $as ?? $neutral];
            }
            if ($codeReadsLikeAPerson) {
                $replace[] = [(string) $code, $as ?? $neutral];
            }
        }
        if ($replace === []) {
            return $data;
        }
        usort($replace, static fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        $patterns = [];
        foreach ($replace as [$name, $code]) {
            $words = array_map(static fn (string $w): string => preg_quote($w, '/'), explode(' ', $name));
            // The replacement goes in as plain text ("$1" or "\1" is never a reference).
            $patterns['/(?<![\p{L}\d])' . implode('[\s\x{00A0}]+', $words) . '(?![\p{L}\d])/iu'] = str_replace(['\\', '$'], ['\\\\', '\\$'], $code);
        }

        return self::replaceIn($data, $patterns);
    }

    private static function readsLikeAPerson(string $name): bool
    {
        return self::requestProblem($name) !== null || self::requestProblem(self::titled($name)) !== null;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<array{0: string, 1: string|int|null}> name => its code (null: the property)
     */
    private static function namedThings(array $data): array
    {
        $things = [];
        if (is_string($data['label'] ?? null) && is_string($data['code'] ?? null) && $data['code'] !== '') {
            $things[] = [$data['label'], $data['code']];
        }
        if (is_string($data['room_label'] ?? null) && is_string($data['room_code'] ?? null) && $data['room_code'] !== '') {
            $things[] = [$data['room_label'], $data['room_code']];
        }
        if (is_string($data['property'] ?? null)) {
            $things[] = [$data['property'], null];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                array_push($things, ...self::namedThings($value));
            }
        }

        return $things;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<string, string>    $patterns
     *
     * @return array<int|string, mixed>
     */
    private static function replaceIn(array $data, array $patterns): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $key = (string) (preg_replace(array_keys($patterns), array_values($patterns), $key) ?? $key);
            }
            if (is_string($value)) {
                $value = (string) (preg_replace(array_keys($patterns), array_values($patterns), $value) ?? '');
            } elseif (is_array($value)) {
                $value = self::replaceIn($value, $patterns);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * The labels in a data tree (values under a "label" key, at any depth).
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<string>
     */
    public static function labels(array $data): array
    {
        $labels = [];
        foreach ($data as $key => $value) {
            if ($key === 'label' && is_string($value)) {
                $labels[] = $value;
            } elseif (is_array($value)) {
                array_push($labels, ...self::labels($value));
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param list<string> $known see requestProblem()
     *
     * @throws AiStopped (privacy) when the person's text looks like guest data
     */
    public static function assertRequestSendable(string $text, array $known = []): void
    {
        $problem = self::requestProblem($text, $known);
        if ($problem !== null) {
            throw new AiStopped(AiStopped::PRIVACY, 'Nothing was sent: the text looks like it carries guest data (an e-mail, a phone number, a document number or a guest\'s name). Refer to a booking by its id, and to a room whose name reads like a person by its code.', ['kind' => $problem]);
        }
    }

    /**
     * The first problem of any string in a data tree (keys included), or null.
     *
     * @param array<int|string, mixed> $data
     */
    public static function problemIn(array $data): ?string
    {
        foreach ($data as $key => $value) {
            $problem = (is_string($key) ? self::problem($key) : null)
                ?? (is_string($value) ? self::problem($value) : null)
                ?? (is_array($value) ? self::problemIn($value) : null);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * @throws AiStopped (privacy) when a text looks like guest data
     */
    public static function assertSendable(string ...$texts): void
    {
        foreach ($texts as $text) {
            $problem = self::problem($text);
            if ($problem !== null) {
                throw new AiStopped(AiStopped::PRIVACY, 'Nothing was sent: the text looks like it carries guest data (an e-mail, a phone number, a document number or a guest\'s name). Refer to a booking by its id.', ['kind' => $problem]);
            }
        }
    }
}
