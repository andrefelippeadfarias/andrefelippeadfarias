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
 *   "asked"...). Nothing is sent then: the person is told why. Dates, amounts, percentages and
 *   booking ids (up to nine digits) are not phone numbers.
 * - requestProblem() and assertRequestSendable(): the same for what the person types to the
 *   assistant, plus a common first name followed by a surname ("Maria Souza") or after an article
 *   or a preposition ("pedido da Maria").
 * - The name checks read the text without accents and invisible characters, with saints and places
 *   ("São João", "Nossa Senhora Aparecida") blanked. The only names they spare are the property's
 *   and its rooms' (names()): a name found wholly inside one of them, with nothing that could be a
 *   surname right after it ("na Suíte Família Luxo nas sextas"), is the room; "a Dona Rosa
 *   Pereira" next to a room "Chalé Dona Rosa", or "a família Souza" next to a room "Família", is a
 *   guest. A period of the calendar whose name is not clear on its own (clearLabel()) is never sent.
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

    /** A leading word for a kind of room ("Quarto", "Suítes do"...), left out of a room's short name. */
    private const ROOM_KIND = '/^(?i:quartos?|su[íi]tes?|chal[ée]s?|rooms?|cabanas?|apartamentos?|aptos?\.?|bangal[ôo]s?|lofts?|studios?|est[úu]dios?)(?:\s+(?i:d[aoe]s?))?\s+/u';

    /** Verbs a request starts with, never a name after "Clientes:" ("Atrair clientes: Subir 5%"). */
    private const NOT_AFTER_MARK = '(?!(?:Subir|Baixar|Aumentar|Reduzir|Diminuir|Manter|Fechar|Abrir|Bloquear|Liberar|Colocar|Deixar|Criar|Mudar|Trocar|Oferecer|Vender|Segurar|Atrair|Raise|Lower|Increase|Decrease|Reduce|Keep|Close|Open|Block|Hold|Offer|Attract)(?![\p{L}\d]))';

    /**
     * A capitalised name right after such a word, with a space, a colon or a dash between them
     * ("hóspede Maria", "Hóspede: Maria", "Hóspedes – Pereira").
     */
    private const NAME_AFTER_TITLE = '/' . self::TITLE_ANY . '(?:\s*[:\-–—]\s*' . self::NOT_AFTER_MARK . '|\s+)\p{Lu}\p{Ll}+/u';

    /** Days, months and holidays: right after a room's name they are not a surname ("Quarto Família Natal inteiro"). */
    private const DAYS_AND_HOLIDAYS = 'Segunda|Terca|Quarta|Quinta|Sexta|Sabado|Domingo|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Janeiro|Fevereiro|Marco|Abril|Maio|Junho|Julho|Agosto|Setembro|Outubro|Novembro|Dezembro|January|February|March|April|May|June|July|August|September|October|November|December|Natal|Reveillon|Carnaval|Pascoa|Christmas|Easter|Feriado|Holiday';

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
     *
     * @param list<string> $known the names of the property and its rooms (names()): a name found
     *                            wholly inside one of them, with nothing like a surname after it,
     *                            is not a person
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
        if (self::nameIn($text, $known, [self::NAME_AFTER_TITLE, self::NAME_BEFORE_VERB])) {
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
     * @param list<string> $known see problem()
     */
    public static function requestProblem(string $text, array $known = []): ?string
    {
        $problem = self::problem($text, $known);
        if ($problem !== null) {
            return $problem;
        }

        return self::nameIn($text, $known, [self::FIRST_AND_SURNAME, self::FIRST_AFTER_ARTICLE]) ? self::NAME : null;
    }

    /**
     * Whether a pattern finds a person's name in the text. The text is read without accents and
     * invisible characters; saints and places ("São João") are blanked first. A name found is not
     * a person only when it lies wholly inside a known name and what comes right after that name
     * cannot be a surname: the end, a sentence mark, a word in lower case, a number, another known
     * name, or a day, month or holiday followed by one of those ("na Dona Rosa nas sextas" is the
     * room; "a Dona Rosa Pereira", "a Dona Rosa O'Brien", "a família Souza" next to a room
     * "Família" are guests).
     *
     * @param list<string> $known
     * @param list<string> $patterns
     */
    private static function nameIn(string $text, array $known, array $patterns): bool
    {
        $text = self::normal($text);
        $places = $text;
        if (preg_match_all(self::NOT_A_PERSON, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$found, $at]) {
                $places = substr_replace($places, str_repeat(' ', strlen($found)), $at, strlen($found));
            }
        }
        $names = self::occurrences($text, array_map(self::normal(...), $known));
        foreach ($patterns as $pattern) {
            $offset = 0;
            while (($result = preg_match($pattern, $places, $match, PREG_OFFSET_CAPTURE, $offset)) === 1) {
                [$found, $at] = $match[0];
                if (!self::insideKnown($text, $at, $at + strlen($found), $names)) {
                    return true;
                }
                // On to the next character (a whole UTF-8 character, never half of one).
                $offset = $at + max(1, strlen(mb_substr($found, 0, 1, 'UTF-8')));
            }
            if ($result === false) {
                // A text the pattern cannot read is refused, never let through.
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: int}> $names byte ranges of the known names in the text
     */
    private static function insideKnown(string $text, int $at, int $end, array $names): bool
    {
        foreach ($names as [$from, $to]) {
            if ($at < $from) {
                continue;
            }
            // Days, months and holidays right after the name belong to the request, not to a guest.
            $after = $to;
            if (preg_match('/\G(?:[\s\x{00A0}\p{Pd}:]+(?:' . self::DAYS_AND_HOLIDAYS . ')(?![\p{L}\d]))*/u', $text, $days, 0, $to) === 1) {
                $after += strlen($days[0]);
            }
            if ($end <= $after && self::nothingLikeASurname($text, $after, $names)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether what comes at this point of the text cannot continue a person's name.
     *
     * @param list<array{0: int, 1: int}> $names
     */
    private static function nothingLikeASurname(string $text, int $at, array $names): bool
    {
        $rest = substr($text, $at);
        if (preg_match('/^[\s\x{00A0}]*(?:$|[.!?](?:[\s\x{00A0}]|$)|[,;:)\]»"”\p{Pd}][\s\x{00A0}]*(?:$|\p{Ll}|\d))/u', $rest) === 1) {
            return true;
        }
        if (preg_match('/^[\s\x{00A0}]+(?:\d|%|R\$|\p{Ll})/u', $rest) === 1
            && preg_match('/^[\s\x{00A0}]+(?:d[aoe]s?|del|della|di|du|la|le|van|von|der|den|y|mc|mac)[\s\x{00A0}]+\p{Lu}/iu', $rest) !== 1) {
            return true;
        }
        // Another known name next: a list of rooms ("Dona Flor – Standard e Master").
        if (preg_match('/^(?:[\s\x{00A0}]*[\p{Pd},\/][\s\x{00A0}]*|[\s\x{00A0}]+(?:e|and|ou|or)[\s\x{00A0}]+|[\s\x{00A0}]+)/u', $rest, $separator) === 1) {
            $next = $at + strlen($separator[0]);
            foreach ($names as [$from]) {
                if ($from === $next) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The text without accents (NFD, combining marks removed) and without invisible characters
     * (format characters such as a zero-width space or a soft hyphen).
     */
    private static function normal(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = (string) (\Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text);
        }

        // A zero-width space or a word joiner separates words; other invisible characters (a soft
        // hyphen inside "hós-pede") are dropped.
        $text = (string) (preg_replace('/[\x{200B}\x{2060}\x{FEFF}]/u', ' ', $text) ?? $text);

        return (string) (preg_replace('/[\p{Mn}\p{Cf}]+/u', '', $text) ?? $text);
    }

    /**
     * Whether a label typed by a person (a period of the calendar) is clear of guest data on its
     * own, as it is and in title case ("CASAMENTO DA FAMÍLIA SOUZA" is "Casamento Da Família Souza").
     */
    public static function clearLabel(string $label): bool
    {
        return self::requestProblem($label) === null
            && self::requestProblem(mb_convert_case($label, MB_CASE_TITLE, 'UTF-8')) === null;
    }

    /**
     * The byte ranges of the known names in the (normalised) text: whole words, any case, any run
     * of spaces, also without a leading word for a kind of room.
     *
     * @param list<string> $known normalised
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function occurrences(string $text, array $known): array
    {
        $names = [];
        foreach (self::variants($known) as $name) {
            $words = preg_split('/[\s\x{00A0}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
            if ($words === false || $words === []) {
                continue;
            }
            $pattern = '/(?<![\p{L}\d])' . implode('[\s\x{00A0}]+', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)) . '(?![\p{L}\d])/iu';
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$found, $at]) {
                    $names[] = [$at, $at + strlen($found)];
                }
            }
        }

        return $names;
    }

    /**
     * The known names, each also without a leading word for a kind of room ("Quarto Dona Flor" is
     * also "Dona Flor") when two or more words are left.
     *
     * @param list<string> $known
     *
     * @return list<string>
     */
    private static function variants(array $known): array
    {
        $variants = [];
        foreach ($known as $name) {
            $name = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $name));
            if ($name === '' || preg_match('//u', $name) !== 1) {
                continue;
            }
            $variants[] = $name;
            $short = (string) preg_replace(self::ROOM_KIND, '', $name);
            if ($short !== $name && count(explode(' ', $short)) >= 2) {
                $variants[] = $short;
            }
        }

        return array_values(array_unique($variants));
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
     * The names in a data tree that the plugin takes from the property's setup, never typed about a
     * guest: the property ("property", "property_label") and its rooms ("room_label", the "label"
     * of each item of a "rooms" list), at any depth. They are the only known names.
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<string>
     */
    public static function names(array $data): array
    {
        $names = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['property', 'property_label', 'room_label'], true) && is_string($value)) {
                $names[] = $value;
            } elseif ($key === 'rooms' && is_array($value)) {
                foreach ($value as $room) {
                    if (is_array($room) && is_string($room['label'] ?? null)) {
                        $names[] = $room['label'];
                    }
                }
            } elseif (is_array($value)) {
                array_push($names, ...self::names($value));
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => trim($n) !== '')));
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
     * The first problem of any string in a data tree (keys included), or null. The names of the
     * property and its rooms (names()) are never taken for a person; every other text, the labels
     * of periods included, is checked as it is.
     *
     * @param array<int|string, mixed> $data
     * @param list<string>|null        $known the names of the whole tree (null: this tree's names())
     */
    public static function problemIn(array $data, ?array $known = null): ?string
    {
        $known ??= self::names($data);
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
     * assertSendable() for texts that may name the property and its rooms.
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
