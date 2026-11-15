<?php

declare(strict_types=1);

namespace Ritmo\Admin\Market;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Application\Market\CompetitorInput;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Domain\Market\AnchorRuler;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Market\Series\ComparableFactor;
use Ritmo\Domain\Market\Series\NightValue;
use Ritmo\Domain\Pricing\Ladder\RulerPoint;

/**
 * The words of the market screen: every problem of a price file as a plain sentence ("Line 7,
 * column price: the price is not a number ("abc")."), every refusal, where a market value came
 * from, and a competitor's comparable factor. Never a code.
 */
final class MarketText
{
    private const VALUE_SHOWN = 40;

    /** Up to this many problems of one kind in one column are listed one by one. */
    public const GROUP_FROM = 3;

    private function __construct()
    {
    }

    public static function problem(MarketCsvProblem $problem): string
    {
        $what = self::what($problem->code);
        if ($problem->line === 0) {
            return $problem->column === '' ? $what : sprintf(/* translators: 1: column name, 2: what is wrong */ __('Column %1$s: %2$s', 'ritmo-revenue'), $problem->column, $what);
        }
        $where = $problem->column === ''
            /* translators: %d: line of the file */
            ? sprintf(__('Line %d', 'ritmo-revenue'), $problem->line)
            /* translators: 1: line of the file, 2: column name */
            : sprintf(__('Line %1$d, column %2$s', 'ritmo-revenue'), $problem->line, $problem->column);
        $value = match ($problem->value) {
            '' => '',
            MarketCsvProblem::HIDDEN_VALUE => ' ' . __('(value hidden: it looks like personal data, which is never kept)', 'ritmo-revenue'),
            /* translators: %s: the cell as typed */
            default => ' ' . sprintf(__('("%s")', 'ritmo-revenue'), mb_substr($problem->value, 0, self::VALUE_SHOWN)),
        };

        return $where . ': ' . $what . $value;
    }

    /**
     * The problems as sentences, the problems of the header first; more than GROUP_FROM problems
     * of the same kind in the same column become one sentence with their count and lines, so a
     * problem of the header is never buried under a hundred repeated notes.
     *
     * @param list<MarketCsvProblem> $problems
     *
     * @return list<string>
     */
    public static function grouped(array $problems): array
    {
        usort($problems, static fn (MarketCsvProblem $a, MarketCsvProblem $b): int => [$a->line > 1 ? 1 : 0, $a->line] <=> [$b->line > 1 ? 1 : 0, $b->line]);
        $groups = [];
        foreach ($problems as $problem) {
            $groups[$problem->code . '|' . $problem->column][] = $problem;
        }
        $out = [];
        foreach ($groups as $group) {
            if (count($group) <= self::GROUP_FROM) {
                foreach ($group as $problem) {
                    $out[] = self::problem($problem);
                }
                continue;
            }
            $first = $group[0];
            $last = $group[count($group) - 1];
            $out[] = $first->column === ''
                /* translators: 1: number of lines, 2: first line, 3: last line, 4: what is wrong */
                ? sprintf(__('%1$d lines (from line %2$d to %3$d): %4$s', 'ritmo-revenue'), count($group), $first->line, $last->line, self::what($first->code))
                /* translators: 1: number of lines, 2: first line, 3: last line, 4: column name, 5: what is wrong */
                : sprintf(__('%1$d lines (from line %2$d to %3$d), column %4$s: %5$s', 'ritmo-revenue'), count($group), $first->line, $last->line, $first->column, self::what($first->code));
        }

        return $out;
    }

    /**
     * What a file would import, each count with its own singular or plural.
     */
    public static function previewSummary(int $valid, int $nights, int $competitors, int $ignored): string
    {
        return sprintf(
            /* translators: 1: "3 valid rows", 2: "2 nights", 3: "1 competitor", 4: "0 lines ignored" */
            __('%1$s for %2$s and %3$s; %4$s.', 'ritmo-revenue'),
            /* translators: %s: a number */
            sprintf(_n('%s valid row', '%s valid rows', $valid, 'ritmo-revenue'), number_format_i18n($valid)),
            /* translators: %s: a number */
            sprintf(_n('%s night', '%s nights', $nights, 'ritmo-revenue'), number_format_i18n($nights)),
            /* translators: %s: a number */
            sprintf(_n('%s competitor', '%s competitors', $competitors, 'ritmo-revenue'), number_format_i18n($competitors)),
            /* translators: %s: a number */
            sprintf(_n('%s line ignored', '%s lines ignored', $ignored, 'ritmo-revenue'), number_format_i18n($ignored)),
        );
    }

    public static function what(string $code): string
    {
        return match ($code) {
            MarketCsvProblem::MISSING_COLUMN => __('this required column is missing.', 'ritmo-revenue'),
            MarketCsvProblem::NOT_UTF8 => __('the file is not text the plugin can read. Save it as UTF-8 and try again.', 'ritmo-revenue'),
            MarketCsvProblem::TOO_MANY_ROWS => __('the file has more rows than allowed. Split it into smaller files.', 'ritmo-revenue'),
            MarketCsvProblem::TOO_MANY_COLUMNS => __('the file has more than 50 columns.', 'ritmo-revenue'),
            MarketCsvProblem::FILE_TOO_LARGE => __('the file is larger than allowed.', 'ritmo-revenue'),
            MarketCsvProblem::NOT_CSV => __('this is not a CSV text file.', 'ritmo-revenue'),
            MarketCsvProblem::EMPTY_FILE => __('the file is empty or could not be uploaded.', 'ritmo-revenue'),
            MarketCsvProblem::ALREADY_IMPORTED => __('this file was already imported.', 'ritmo-revenue'),
            MarketCsvProblem::UNKNOWN_COMPETITOR => __('no competitor of this property has this name or code.', 'ritmo-revenue'),
            MarketCsvProblem::BAD_DATE => __('the date is not a date written as YYYY-MM-DD.', 'ritmo-revenue'),
            MarketCsvProblem::CHECKOUT_NOT_AFTER_CHECKIN => __('the check-out must be after the check-in.', 'ritmo-revenue'),
            MarketCsvProblem::STAY_TOO_LONG => __('the stay is longer than allowed.', 'ritmo-revenue'),
            MarketCsvProblem::STAY_IN_PAST => __('the stay has already started; past prices are not imported.', 'ritmo-revenue'),
            /* translators: %s: an example amount with cents, in the reader's format */
            MarketCsvProblem::BAD_PRICE => sprintf(__('the price is not a number above zero (such as 480 or %s, without thousands separators).', 'ritmo-revenue'), number_format_i18n(480.5, 2)),
            MarketCsvProblem::BAD_BASIS => __('the price basis is "total" or "per_night".', 'ritmo-revenue'),
            MarketCsvProblem::BAD_ROOM => __('the room is "cheapest" or "comparable".', 'ritmo-revenue'),
            MarketCsvProblem::BAD_CURRENCY => __('the currency is a three-letter code, such as BRL.', 'ritmo-revenue'),
            MarketCsvProblem::CURRENCY_MISMATCH => __('the price is in another currency than the property\'s.', 'ritmo-revenue'),
            MarketCsvProblem::BAD_CAPTURE => __('the capture date is not a date or a date and time.', 'ritmo-revenue'),
            MarketCsvProblem::CAPTURE_IN_FUTURE => __('the capture date is in the future.', 'ritmo-revenue'),
            MarketCsvProblem::BAD_AVAILABLE => __('available is 1 or 0, yes or no.', 'ritmo-revenue'),
            MarketCsvProblem::BAD_SOURCE => __('the source is a short word of lowercase letters and digits.', 'ritmo-revenue'),
            MarketCsvProblem::CELL_TOO_LONG => __('a cell is longer than 1000 characters.', 'ritmo-revenue'),
            MarketCsvProblem::ROW_NOT_UTF8 => __('the line has characters the plugin cannot read.', 'ritmo-revenue'),
            MarketCsvProblem::CAPTURE_TOO_OLD => __('the price is older than the prices that count; it is kept for history only.', 'ritmo-revenue'),
            MarketCsvProblem::DUPLICATE_IN_FILE => __('the same price appears again later in the file; the later line was kept.', 'ritmo-revenue'),
            MarketCsvProblem::CAPTURE_MISSING => __('no capture date: the time of the import was used.', 'ritmo-revenue'),
            MarketCsvProblem::UNKNOWN_COLUMN => __('this column is not used and was ignored.', 'ritmo-revenue'),
            MarketCsvProblem::GUEST_COLUMN => __('not imported: the plugin never stores guest data.', 'ritmo-revenue'),
            MarketCsvProblem::DUPLICATE_COLUMN => __('this column appears twice; the second one was ignored.', 'ritmo-revenue'),
            default => __('this value cannot be read.', 'ritmo-revenue'),
        };
    }

    public static function refusal(MarketRefused $refused, int $maxBytes = 0): string
    {
        return match ($refused->reason) {
            MarketRefused::PROPERTY_UNKNOWN => __('Choose an existing property.', 'ritmo-revenue'),
            MarketRefused::COMPETITOR_UNKNOWN => __('That competitor does not exist.', 'ritmo-revenue'),
            MarketRefused::REQUIRED => __('Give the competitor a name.', 'ritmo-revenue'),
            /* translators: %d: maximum number of characters */
            MarketRefused::TOO_LONG => sprintf(__('A text here has at most %d characters.', 'ritmo-revenue'), (int) ($refused->params['max'] ?? 0)),
            MarketRefused::PRIVACY => __('Do not write guest names, e-mails, phone numbers or document numbers here. Refer to a booking by its id.', 'ritmo-revenue'),
            MarketRefused::BAD_CODE => __('A code has lowercase letters, digits and dashes.', 'ritmo-revenue'),
            MarketRefused::CODE_TAKEN => __('Another competitor already has this code.', 'ritmo-revenue'),
            MarketRefused::BAD_TAG => __('A tag is a short lowercase word, such as peer.', 'ritmo-revenue'),
            /* translators: %s: an example factor, in the reader's format */
            MarketRefused::BAD_FACTOR => sprintf(__('The factor is a number above 0 and at most 10, such as %s.', 'ritmo-revenue'), number_format_i18n(1.4, 1)),
            MarketRefused::BAD_RATING => __('The rating is a number from 0 to 10.', 'ritmo-revenue'),
            /* translators: %s: date of the earlier import */
            MarketRefused::ALREADY_IMPORTED => sprintf(__('This file was already imported on %s. Nothing was imported.', 'ritmo-revenue'), self::date((string) ($refused->params['date'] ?? ''))),
            MarketRefused::FILE_ERROR => self::fileError((string) ($refused->params['code'] ?? ''), $refused->field, $maxBytes),
            MarketRefused::NOTHING_TO_IMPORT => __('No valid row is left to import. Nothing was imported.', 'ritmo-revenue'),
            MarketRefused::PREVIEW_EXPIRED => __('The preview is no longer available (it lasts 30 minutes). Upload the file again.', 'ritmo-revenue'),
            MarketRefused::PREVIEW_TOO_LARGE => __('The file has too many valid rows for one import. Split the file.', 'ritmo-revenue'),
            /* translators: %s: what is wrong with the price */
            MarketRefused::BAD_PRICE => sprintf(__('The price was not recorded: %s', 'ritmo-revenue'), self::what((string) ($refused->params['code'] ?? ''))),
            default => __('Something went wrong. Nothing was changed.', 'ritmo-revenue'),
        };
    }

    /**
     * The name of a price series as the screen shows it.
     */
    public static function series(string $name): string
    {
        return match ($name) {
            'cheapest' => __('Cheapest rooms', 'ritmo-revenue'),
            'comparable' => __('Comparable rooms', 'ritmo-revenue'),
            'peers_cheapest' => __('Closest competitors, cheapest rooms', 'ritmo-revenue'),
            'peers_comparable' => __('Closest competitors, comparable rooms', 'ritmo-revenue'),
            AnchorRuler::SERIES => __('Market anchor', 'ritmo-revenue'),
            /* translators: %s: name of a price series the site configured */
            default => sprintf(__('Series %s', 'ritmo-revenue'), $name),
        };
    }

    /**
     * A point of the market a sales ladder rule reads: "Comparable rooms, percentile 50".
     */
    public static function point(RulerPoint $point): string
    {
        /* translators: 1: name of a price series, 2: a percentile such as 50 */
        return sprintf(__('%1$s, percentile %2$s', 'ritmo-revenue'), self::series($point->series), self::number($point->percentile));
    }

    public static function origin(NightValue $value): string
    {
        return match ($value->origin) {
            NightValue::NIGHT => __('measured on stays starting that night', 'ritmo-revenue'),
            NightValue::CONTAINING => __('stays that include the night', 'ritmo-revenue'),
            /* translators: %d: number of days */
            NightValue::NEIGHBOUR => sprintf(_n('nearest stays, %d day away', 'nearest stays, %d days away', (int) $value->distanceDays, 'ritmo-revenue'), (int) $value->distanceDays),
            default => __('typical value for this kind of night', 'ritmo-revenue'),
        };
    }

    public static function factor(?float $factor, string $source, float $default, float $cap): string
    {
        if ($factor === null || $factor == 0.0) {
            /* translators: %s: the default factor */
            return sprintf(__('× %s (not set: default)', 'ritmo-revenue'), self::number($default));
        }
        if ($source === ComparableFactor::ESTIMATED) {
            return $factor > $cap
                /* translators: %s: the factor used */
                ? sprintf(__('× %s (estimated, capped)', 'ritmo-revenue'), self::number($cap))
                /* translators: %s: the factor */
                : sprintf(__('× %s (estimated)', 'ritmo-revenue'), self::number($factor));
        }

        /* translators: %s: the factor */
        return $source === ComparableFactor::MEASURED ? sprintf(__('× %s (measured)', 'ritmo-revenue'), self::number($factor)) : sprintf(__('× %s', 'ritmo-revenue'), self::number($factor));
    }

    private static function fileError(string $code, string $column, int $maxBytes): string
    {
        if ($code === MarketCsvProblem::FILE_TOO_LARGE && $maxBytes > 0) {
            /* translators: %s: size limit, such as 2 MB */
            return sprintf(__('The file is larger than %s. Nothing was imported.', 'ritmo-revenue'), size_format($maxBytes));
        }
        $what = self::what($code);

        return ($column === ''
            /* translators: %s: what is wrong with the file */
            ? sprintf(__('The file: %s', 'ritmo-revenue'), $what)
            /* translators: 1: column name, 2: what is wrong */
            : sprintf(__('Column %1$s: %2$s', 'ritmo-revenue'), $column, $what)) . ' ' . __('Nothing was imported.', 'ritmo-revenue');
    }

    /**
     * A factor in the person's format, with up to three decimals ("1,4" in pt_BR).
     */
    private static function number(float $value): string
    {
        $plain = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
        $decimals = str_contains($plain, '.') ? strlen($plain) - strpos($plain, '.') - 1 : 0;

        return number_format_i18n($value, $decimals);
    }

    /**
     * The groups of a competitor in words: the peer group by its meaning, the others as typed.
     *
     * @param list<string> $tags
     */
    public static function groups(array $tags): string
    {
        return $tags === [] ? '—' : implode(', ', array_map(static fn (string $tag): string => $tag === CompetitorInput::PEER ? __('close to you (peer)', 'ritmo-revenue') : $tag, $tags));
    }

    private static function date(string $ymd): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));

        return $date === false ? $ymd : wp_date(get_option('date_format'), $date->getTimestamp(), new DateTimeZone('UTC'));
    }
}
