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
 *   "hóspede", "cliente", "Sr.", "guest"..., also after a colon or a dash; after a plural and a
 *   colon, a first name and a surname or a common first name; or two capitalised words before
 *   "pediu", "asked"...). Nothing is sent
 *   then: the person is told why. Dates, amounts, percentages and booking ids (up to nine digits)
 *   are not phone numbers. The names of the property's rooms and periods (the labels of the data
 *   sent, "Suíte Família Luxo") are never taken for a person.
 * - requestProblem() and assertRequestSendable(): the same for what the person types to the
 *   assistant, plus a common first name followed by a surname ("Maria Souza") or after an article
 *   or a preposition ("pedido da Maria"). The names of the property's rooms and periods, and
 *   saints and places ("São João", "Nossa Senhora Aparecida"), are left out of that check.
 *
 * The name checks are a heuristic: the screen also asks the person never to write a guest's name.
 */
final class PrivacyGuard
{
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const DOCUMENT = 'document';
    public const NAME = 'name';

    /** A word that introduces one person ("hóspede", "cliente", "Sr.", "guest"...). */
    private const TITLE_ONE = '(?<![\p{L}\d])(?i:h[óo]spede|cliente|fam[íi]lia|sra?\.?|srta\.?|senhora?|dona|guest|customer|mrs?\.?|ms\.?|miss)';

    /** The same words, plurals too ("hóspedes", "clientes", "guests"...). */
    private const TITLE_ANY = '(?<![\p{L}\d])(?i:h[óo]spedes?|clientes?|fam[íi]lia|sra?\.?|srta\.?|senhora?|dona|guests?|customers?|mrs?\.?|ms\.?|miss)';

    /** A colon or a dash between the word and the name. */
    private const MARK = '\s*[:\-–—]\s*';

    /** A capitalised name right after such a word ("hóspede Maria"). */
    private const NAME_AFTER_TITLE = '/' . self::TITLE_ANY . '\s+\p{Lu}\p{Ll}+/u';

    /**
     * After a colon or a dash: any capitalised word after a word for one person ("Hóspede: Maria",
     * "O cliente — Pereira"); after a plural, only a first name and a surname or a common first
     * name ("Hóspedes: Maria Souza"), so "Atrair clientes: Subir 5%" is not a name.
     */
    private const NAME_AFTER_TITLE_MARK = '/' . self::TITLE_ONE . self::MARK . '\p{Lu}\p{Ll}+|' . self::TITLE_ANY . self::MARK . '(?:\p{Lu}\p{Ll}+\s+(?:(?:d[aoe]s?|von|van)\s+)?\p{Lu}\p{Ll}+|(?:' . self::FIRST_NAMES . ')(?![\p{L}\d]))/u';

    /** Saints, places and holidays that read like a title and a name ("Nossa Senhora Aparecida", "São João"). */
    private const NOT_A_PERSON = '/(?<![\p{L}\d])(?:(?i:nossa)\s+(?i:senhora)(?:\s+(?:d[aoe]s?\s+)?\p{Lu}\p{Ll}+)?|(?:São|Sao|Santa|Santo|Saint|St\.)\s+\p{Lu}\p{Ll}+)/u';

    /** Common first names (Brazilian and English) that are not also usual words, places or months. */
    private const FIRST_NAMES = 'Maria|José|Jose|Ana|João|Joao|Antônio|Antonio|Francisco|Francisca|Carlos|Paulo|Pedro|Lucas|Luiz|Luis|Luís|Marcos|Luciana|Gabriel|Gabriela|Rafael|Rafaela|Daniel|Daniela|Marcelo|Bruno|Bruna|Eduardo|Felipe|Raimundo|Raimunda|Rodrigo|Manoel|Manuel|Mateus|Matheus|André|Andre|Fernando|Fernanda|Fábio|Fabio|Fabiana|Leonardo|Gustavo|Guilherme|Leandro|Tiago|Thiago|Anderson|Ricardo|Márcio|Marcio|Márcia|Marcia|Jorge|Sebastião|Alexandre|Roberto|Roberta|Edson|Diego|Vitor|Victor|Sérgio|Sergio|Cláudio|Claudio|Cláudia|Claudia|Juliana|Adriana|Patrícia|Patricia|Aline|Sandra|Camila|Amanda|Jéssica|Jessica|Letícia|Leticia|Júlia|Julia|Luana|Mariana|Beatriz|Larissa|Vanessa|Carla|Débora|Debora|Renata|Renato|Simone|Cristiane|Tatiane|Eliane|Raquel|Sônia|Sonia|Rosana|Isabela|Isabella|Carolina|Paula|Priscila|Natália|Natalia|Helena|Laura|Alice|Sofia|Sophia|Valentina|Manuela|Lorena|Heloísa|Heloisa|Cecília|Cecilia|Lívia|Livia|Giovanna|Bianca|Vinícius|Vinicius|Caio|Enzo|Miguel|Arthur|Artur|Davi|Bernardo|Heitor|Samuel|Henrique|Murilo|Igor|Otávio|Otavio|Wagner|Wellington|Rogério|Rogerio|Kelly|Michele|Michelle|Sílvia|Silvia|Regina|Teresa|Tereza|Lúcia|Lucia|Rita|Joana|Josefa|Antônia|Antonia|Marta|Denise|Elaine|Mônica|Monica|Luíza|Luiza|Lara|Yasmin|John|Mary|James|Robert|Michael|William|David|Richard|Joseph|Thomas|George|Peter|Jennifer|Linda|Elizabeth|Susan|Sarah|Karen|Nancy|Lisa|Emma|Olivia|Emily|Anna|Kate';

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
     *
     * @param list<string> $known names of the property's rooms and periods, never taken for a person
     */
    public static function problem(string $text, array $known = []): ?string
    {
        if (preg_match(self::EMAIL_PATTERN, $text) === 1) {
            return self::EMAIL;
        }
        foreach (self::DOCUMENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return self::DOCUMENT;
            }
        }
        $people = (string) preg_replace(self::NOT_A_PERSON, ' ', self::withoutKnown($text, $known));
        if (preg_match(self::NAME_AFTER_TITLE, $people) === 1 || preg_match(self::NAME_AFTER_TITLE_MARK, $people) === 1
            || preg_match(self::NAME_BEFORE_VERB, $people) === 1) {
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
     * problem() for a text the person typed to the assistant, with the first-name check too.
     *
     * @param list<string> $known names of the property's rooms and periods, never taken for a person
     */
    public static function requestProblem(string $text, array $known = []): ?string
    {
        $problem = self::problem($text, $known);
        if ($problem !== null) {
            return $problem;
        }
        $people = (string) preg_replace(self::NOT_A_PERSON, ' ', self::withoutKnown($text, $known));
        if (preg_match(self::FIRST_AND_SURNAME, $people) === 1 || preg_match(self::FIRST_AFTER_ARTICLE, $people) === 1) {
            return self::NAME;
        }

        return null;
    }

    /**
     * The text without the known names (whole words only, any case), longest first.
     *
     * @param list<string> $known
     */
    private static function withoutKnown(string $text, array $known): string
    {
        $known = array_values(array_filter(array_map('trim', $known), static fn (string $name): bool => $name !== ''));
        usort($known, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($known as $name) {
            $text = (string) (preg_replace('/(?<![\p{L}\d])' . preg_quote($name, '/') . '(?![\p{L}\d])/iu', ' ', $text) ?? $text);
        }

        return $text;
    }

    /**
     * The labels in a data tree (values under a "label" or "room_label" key, at any depth, and the
     * name before the colon of a label such as "Garden Suite: room nights sold"): the names of
     * rooms and periods that problem() and requestProblem() never take for a person.
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<string>
     */
    public static function labels(array $data): array
    {
        $labels = [];
        foreach ($data as $key => $value) {
            if (($key === 'label' || $key === 'room_label') && is_string($value)) {
                $labels[] = $value;
                $colon = mb_strpos($value, ':');
                if ($colon !== false && trim(mb_substr($value, 0, $colon)) !== '') {
                    $labels[] = trim(mb_substr($value, 0, $colon));
                }
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
            throw new AiStopped(AiStopped::PRIVACY, 'Nothing was sent: the text looks like it carries guest data (an e-mail, a phone number, a document number or a guest\'s name). Refer to a booking by its id.', ['kind' => $problem]);
        }
    }

    /**
     * The first problem of any string in a data tree (keys included), or null. The labels of the
     * tree (labels()) are the names of rooms and periods, never taken for a person.
     *
     * @param array<int|string, mixed> $data
     * @param list<string>|null        $known the labels of the whole tree (null: this tree's)
     */
    public static function problemIn(array $data, ?array $known = null): ?string
    {
        $known ??= self::labels($data);
        foreach ($data as $key => $value) {
            $problem = (is_string($key) ? self::problem($key, $known) : null)
                ?? (is_string($value) ? self::problem($value, $known) : null)
                ?? (is_array($value) ? self::problemIn($value, $known) : null);
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
        self::assertSendableKnowing([], ...$texts);
    }

    /**
     * assertSendable() for texts that may name the property's rooms and periods.
     *
     * @param list<string> $known see problem()
     *
     * @throws AiStopped (privacy) when a text looks like guest data
     */
    public static function assertSendableKnowing(array $known, string ...$texts): void
    {
        foreach ($texts as $text) {
            $problem = self::problem($text, $known);
            if ($problem !== null) {
                throw new AiStopped(AiStopped::PRIVACY, 'Nothing was sent: the text looks like it carries guest data (an e-mail, a phone number, a document number or a guest\'s name). Refer to a booking by its id.', ['kind' => $problem]);
            }
        }
    }
}
