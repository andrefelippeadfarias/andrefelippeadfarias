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
 *   "hóspede", "clientes", "Sr.", "guest"..., with a space, a colon or a dash between them, except
 *   a verb a request starts with, "Clientes: Subir 5%"; or two capitalised words before "pediu",
 *   "asked"...). Nothing is sent then: the person is told why. Dates, date ranges, season years,
 *   amounts, percentages and booking ids (up to nine digits) are not phone numbers.
 * - requestProblem() and assertRequestSendable(): the same for what the person types to the
 *   assistant, plus a common first name followed by a surname ("Maria Souza") or after an article
 *   or a preposition ("pedido da Maria").
 * - The name checks read the text without accents and invisible characters, with saints and places
 *   ("São João", "Nossa Senhora Aparecida") blanked. They spare nothing else.
 * - masked(): the data sent never carries a name of the property or of a room that reads like a
 *   person ("Suíte Família Luxo", "Chalé Dona Rosa"): it goes as the room's code (the property as
 *   "the property"), so such a name never blocks the assistant; the person writes the code.
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

    /** Verbs a request starts with, never a name after "Clientes:" ("Atrair clientes: Subir 5%"). */
    private const NOT_AFTER_MARK = '(?!(?:Subir|Baixar|Aumentar|Reduzir|Diminuir|Manter|Fechar|Abrir|Bloquear|Liberar|Colocar|Deixar|Criar|Mudar|Trocar|Oferecer|Vender|Segurar|Atrair|Raise|Lower|Increase|Decrease|Reduce|Keep|Close|Open|Block|Hold|Offer|Attract)(?![\p{L}\d]))';

    /**
     * A capitalised name right after such a word, with a space, a colon or a dash between them
     * ("hóspede Maria", "Hóspede: Maria", "Hóspedes – Pereira").
     */
    private const NAME_AFTER_TITLE = '/' . self::TITLE_ANY . '(?:\s*[:\-–—]\s*' . self::NOT_AFTER_MARK . '|\s+)\p{Lu}\p{Ll}+/u';

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
        // Season years and ranges of days ("2026/2027", "24/12-26/12").
        '/(?<!\d)(?:19|20)\d{2}\s?[\/\-–]\s?(?:19|20)\d{2}(?!\d)/u',
        '/(?<!\d)\d{1,2}\/\d{1,2}\s?[\-–]\s?\d{1,2}\/\d{1,2}(?!\d)/u',
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
     * problem() for a text the person typed to the assistant, with the first-name checks too.
     */
    public static function requestProblem(string $text): ?string
    {
        return self::problem($text) ?? (self::nameIn($text, [self::FIRST_AND_SURNAME, self::FIRST_AFTER_ARTICLE]) ? self::NAME : null);
    }

    /**
     * Whether one of the patterns finds a person's name: in the text without accents and invisible
     * characters, saints and places ("São João") blanked. A text the patterns cannot read is
     * refused, never let through.
     *
     * @param list<string> $patterns
     */
    private static function nameIn(string $text, array $patterns): bool
    {
        $text = self::normal($text);
        $people = preg_replace_callback(self::NOT_A_PERSON, static fn (array $m): string => str_repeat(' ', strlen($m[0])), $text);
        if ($people === null) {
            return true;
        }
        foreach ($patterns as $pattern) {
            $found = preg_match($pattern, $people);
            if ($found !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text without accents (NFD, combining marks removed) and without invisible characters; a
     * zero-width space or a word joiner separates words, a soft hyphen inside a word is dropped.
     */
    private static function normal(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = (string) (\Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text);
        }
        $text = (string) (preg_replace('/[\x{200B}\x{2060}\x{FEFF}]/u', ' ', $text) ?? $text);

        return (string) (preg_replace('/[\p{Mn}\p{Cf}]+/u', '', $text) ?? $text);
    }

    /**
     * Whether a label typed by a person (a period of the calendar) is clear of guest data: as it
     * is, and in title case when it is written all in capitals or all in lower case
     * ("CASAMENTO DA FAMÍLIA SOUZA").
     */
    public static function clearLabel(string $label): bool
    {
        if (self::requestProblem($label) !== null) {
            return false;
        }
        if ($label === mb_strtoupper($label, 'UTF-8') || $label === mb_strtolower($label, 'UTF-8')) {
            return self::requestProblem(mb_convert_case($label, MB_CASE_TITLE, 'UTF-8')) === null;
        }

        return true;
    }

    /**
     * The data with every name of the property or of a room that reads like a person (requestProblem())
     * replaced, in every string, by the room's code (the property's by "the property"). The rooms
     * come from the items of a "rooms" list with "code" and "label", and from "room_code" with
     * "room_label"; the property from "property" (a name). Only text is taken out: nothing can
     * reach the assistant that was not in the data.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public static function masked(array $data): array
    {
        $replace = [];
        foreach (self::namedThings($data) as [$name, $code]) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));
            if ($name !== '' && self::requestProblem($name) !== null) {
                $replace[$name] = $code;
            }
        }
        if ($replace === []) {
            return $data;
        }
        uksort($replace, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $patterns = [];
        foreach ($replace as $name => $code) {
            $words = array_map(static fn (string $w): string => preg_quote($w, '/'), explode(' ', $name));
            // The code goes in as plain text ("$1" or "\\1" in a code is never a reference).
            $patterns['/(?<![\p{L}\d])' . implode('[\s\x{00A0}]+', $words) . '(?![\p{L}\d])/iu'] = str_replace(['\\', '$'], ['\\\\', '\\$'], $code);
        }

        return self::replaceIn($data, $patterns);
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<array{0: string, 1: string}> name => what it is replaced by
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
            $things[] = [$data['property'], 'the property'];
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
     * @throws AiStopped (privacy) when the person's text looks like guest data
     */
    public static function assertRequestSendable(string $text): void
    {
        $problem = self::requestProblem($text);
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
