<?php

declare(strict_types=1);

namespace Ritmo\Rest\Market;

use Closure;
use DateTimeImmutable;
use Ritmo\Admin\Market\MarketText;
use Ritmo\Application\Market\CompetitorService;
use Ritmo\Application\Market\ImportPreview;
use Ritmo\Application\Market\MarketBoard;
use Ritmo\Application\Market\MarketImportService;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Application\Market\MarketSettings;
use Ritmo\Application\Market\UploadedFile;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\NightValue;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;

/**
 * The answers of the market REST routes (market spec 1.9), as [status, body]. The route has
 * already checked the capability and the REST nonce; this class looks the property up, calls the
 * use case and turns every refusal into a translated sentence. A body never carries a cell of an
 * uploaded file, an exception message, SQL or a path.
 */
final class MarketEndpoints
{
    public const RULER_MAX_NIGHTS = 120;

    private const RULER_DEFAULT_NIGHTS = 90;

    /**
     * @param Closure(): ?RuleSet $rules the rules in force
     */
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly CompetitorService $competitors,
        private readonly MarketBoard $board,
        private readonly MarketImportService $import,
        private readonly Closure $rules,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function competitors(string $propertyId): array
    {
        if ($this->properties->find($propertyId) === null) {
            return self::notFound();
        }
        $config = $this->config();

        return [200, ['competitors' => array_map(static fn (array $row): array => self::competitor($row, $config), $this->board->competitors($propertyId))]];
    }

    /**
     * Creates a competitor (id null) or changes one: the fields given replace the stored ones; an
     * "active" of false alone archives it (its prices are kept).
     *
     * @param array<string, mixed> $fields
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function saveCompetitor(string $propertyId, ?int $id, array $fields, int $userId): array
    {
        try {
            if ($id !== null && array_keys($fields) === ['active'] && $fields['active'] === false) {
                $this->competitors->archive($propertyId, $id, $userId);

                return [200, ['id' => $id, 'text' => __('Competitor archived: it no longer counts; its prices are kept.', 'ritmo-revenue')]];
            }
            $stored = $id === null ? [] : $this->stored($propertyId, $id);
            $saved = $this->competitors->save($propertyId, $id, $fields + $stored, $userId);
        } catch (MarketRefused $refused) {
            return self::refused($refused);
        }

        return [$id === null ? 201 : 200, ['id' => $saved, 'text' => __('Competitor saved; the market by night was updated.', 'ritmo-revenue')]];
    }

    /**
     * Reads and checks an uploaded price file and keeps its valid rows for the confirmation.
     *
     * @param Closure(): UploadedFile $upload reads the upload (throws MarketRefused for a file the checks refuse)
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function preview(string $propertyId, Closure $upload, int $userId, int $maxBytes): array
    {
        if ($this->properties->find($propertyId) === null) {
            return self::notFound();
        }
        try {
            $preview = $this->import->preview($propertyId, $upload(), $userId);
        } catch (MarketRefused $refused) {
            return self::refused($refused, $maxBytes);
        }

        return [200, self::previewBody($preview)];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function commit(string $propertyId, string $token, int $userId): array
    {
        if ($this->properties->find($propertyId) === null) {
            return self::notFound();
        }
        try {
            $result = $this->import->commit($propertyId, $token, $userId);
        } catch (MarketRefused $refused) {
            return self::refused($refused);
        }

        return [200, [
            'imported' => $result['imported'],
            'replaced' => $result['replaced'],
            'skipped' => $result['skipped'],
            'nights' => $result['nights'],
            'text' => sprintf(
                /* translators: 1: number of prices, 2: number of nights */
                _n('%1$d price recorded; market values updated for %2$d nights.', '%1$d prices recorded; market values updated for %2$d nights.', $result['imported'], 'ritmo-revenue'),
                $result['imported'],
                $result['nights']
            ),
        ]];
    }

    /**
     * The market by night from $from to $to (default: the next 90 nights; at most 120).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function ruler(string $propertyId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $property = $this->properties->find($propertyId);
        if ($property === null) {
            return self::notFound();
        }
        $first = $from ?? $this->clock->today($property->timezone);
        $last = $to ?? Days::add($first, self::RULER_DEFAULT_NIGHTS - 1);
        $count = Days::diff($first, $last) + 1;
        if ($count < 1 || $count > self::RULER_MAX_NIGHTS) {
            return self::error(400, 'ritmo_bad_range', sprintf(
                /* translators: %d: maximum number of nights */
                __('Choose a last night on or after the first night, at most %d nights later.', 'ritmo-revenue'),
                self::RULER_MAX_NIGHTS
            ));
        }
        $rules = ($this->rules)() ?? RuleSet::defaults();
        $nights = array_map(
            fn (array $row): array => $this->rulerNight($row, $property),
            $this->board->nights($property, $rules, $this->config(), $first, $count)
        );

        return [200, ['currency' => $property->currency, 'nights' => $nights]];
    }

    /**
     * The last ten imports with their counts (never a cell of a file).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function imports(string $propertyId): array
    {
        if ($this->properties->find($propertyId) === null) {
            return self::notFound();
        }

        return [200, ['imports' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'file_name' => (string) $row['file_name'],
            'rows_total' => (int) $row['rows_total'],
            'rows_imported' => (int) $row['rows_imported'],
            'rows_replaced' => (int) $row['rows_replaced'],
            'rows_invalid' => (int) $row['rows_invalid'],
            'created_at' => $row['created_at']->format(DATE_ATOM),
        ], $this->board->imports($propertyId))]];
    }

    /**
     * @param array<string, mixed> $row a row of MarketBoard::competitors
     *
     * @return array<string, mixed>
     */
    private static function competitor(array $row, MarketConfig $config): array
    {
        return [
            'id' => $row['id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'reference' => $row['reference'],
            'room_label' => $row['room_label'],
            'tags' => $row['tags'],
            'factor' => $row['factor'],
            'factor_text' => MarketText::factor($row['factor'], $row['factor_source'], $config->defaultFactor, $config->estimatedFactorCap),
            'rating' => $row['rating'],
            'is_own' => $row['is_own'],
            'active' => $row['active'],
            'prices_last_30_days' => $row['prices_30_days'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $propertyId, int $id): array
    {
        foreach ($this->board->competitors($propertyId) as $row) {
            if ($row['id'] === $id) {
                return [
                    'name' => $row['name'],
                    'code' => $row['code'],
                    'reference' => $row['reference'],
                    'room_label' => $row['room_label'],
                    'tags' => $row['tags'],
                    'factor' => $row['factor'],
                    'factor_source' => $row['factor_source'],
                    'rating' => $row['rating'],
                    'is_own' => $row['is_own'],
                    'active' => $row['active'],
                ];
            }
        }

        throw new MarketRefused(MarketRefused::COMPETITOR_UNKNOWN);
    }

    /**
     * @return array<string, mixed>
     */
    private static function previewBody(ImportPreview $preview): array
    {
        return [
            'token' => $preview->token,
            'valid_rows' => $preview->valid,
            'lines' => $preview->total,
            'invalid_lines' => $preview->invalidLines,
            'nights' => $preview->nights,
            'competitors' => $preview->competitors,
            'first_check_in' => $preview->firstCheckIn === null ? null : Days::key($preview->firstCheckIn),
            'last_check_in' => $preview->lastCheckIn === null ? null : Days::key($preview->lastCheckIn),
            'problems' => array_map(static fn (MarketCsvProblem $p): array => [
                'line' => $p->line,
                'column' => $p->column,
                'kind' => $p->kind,
                'text' => MarketText::problem($p),
            ], $preview->problems),
            'more_problems' => $preview->moreProblems,
            'unknown_competitors' => $preview->unknownCompetitors,
            'text' => sprintf(
                /* translators: 1: number of valid rows, 2: number of nights, 3: number of competitors, 4: number of lines ignored */
                __('%1$d valid rows for %2$d nights and %3$d competitors; %4$d lines ignored. Nothing is stored until you confirm.', 'ritmo-revenue'),
                $preview->valid,
                $preview->nights,
                $preview->competitors,
                $preview->invalidLines
            ),
        ];
    }

    /**
     * @param array{night: DateTimeImmutable, type: string, series: array<string, NightValue>, anchor: \Ritmo\Domain\Model\MarketAnchor|null, age_days: int|null, small_sample: bool} $row
     *
     * @return array<string, mixed>
     */
    private function rulerNight(array $row, Property $property): array
    {
        $series = [];
        foreach ($row['series'] as $name => $value) {
            $series[$name] = [
                'values' => $value->values,
                'competitors' => $value->competitors,
                'stays' => $value->stays,
                'origin' => $value->origin,
                'origin_text' => MarketText::origin($value),
                'newest_capture' => $value->newestCapture === null ? null : Days::key($value->newestCapture),
            ];
        }
        $anchor = $row['anchor'];

        return [
            'night' => Days::key($row['night']),
            'night_type' => $row['type'],
            'series' => $series,
            'market' => $anchor === null ? null : ['25' => $anchor->p25, '50' => $anchor->p50, '75' => $anchor->p75, '90' => $anchor->p90, '100' => $anchor->max, 'prices' => $anchor->n],
            'age_days' => $row['age_days'],
            'small_sample' => $row['small_sample'],
        ];
    }

    private function config(): MarketConfig
    {
        try {
            return MarketSettings::of(($this->rules)());
        } catch (InvalidConfig) {
            return MarketConfig::neutral();
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function refused(MarketRefused $refused, int $maxBytes = 0): array
    {
        $status = match ($refused->reason) {
            MarketRefused::PROPERTY_UNKNOWN, MarketRefused::COMPETITOR_UNKNOWN => 404,
            MarketRefused::ALREADY_IMPORTED, MarketRefused::PREVIEW_EXPIRED => 409,
            default => 400,
        };

        return self::error($status, 'ritmo_' . $refused->reason, MarketText::refusal($refused, $maxBytes), $refused->field);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function notFound(): array
    {
        return self::error(404, 'ritmo_not_found', __('Not found.', 'ritmo-revenue'));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function error(int $status, string $code, string $message, string $field = ''): array
    {
        return [$status, ['code' => $code, 'message' => $message] + ($field === '' ? [] : ['field' => $field])];
    }
}
