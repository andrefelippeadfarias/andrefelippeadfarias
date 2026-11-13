<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use InvalidArgumentException;
use Ritmo\Domain\Market\Csv\CompetitorIndex;
use Ritmo\Domain\Market\Csv\CsvContext;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Market\Csv\MarketCsvReader;
use Ritmo\Domain\Market\Csv\ParsedRow;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\MarketImportRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;

/**
 * The two steps of a market price import (spec 06, 1.2): preview() reads and checks the file and
 * keeps only its valid rows for 30 minutes, bound to the user, the property and the file;
 * commit() stores them in one transaction (a known row is replaced, so importing twice changes
 * nothing) and rebuilds the series and anchors. A file already imported is refused by its hash.
 * Nothing here calls an external system.
 */
final class MarketImportService
{
    public const PREVIEW_SECONDS = 1800;

    /** Largest preview kept (bytes of its plain values). */
    public const PREVIEW_MAX_BYTES = 1048576;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PropertyRepository $properties,
        private readonly RuleSetRepository $ruleSets,
        private readonly CompetitorRepository $competitors,
        private readonly StaySampleRepository $samples,
        private readonly MarketImportRepository $imports,
        private readonly EditorEventRepository $events,
        private readonly MarketSeriesService $series,
        private readonly PreviewStore $previews,
    ) {
    }

    /**
     * @throws MarketRefused when the property is unknown, the file was imported before or a file error stops it
     */
    public function preview(string $propertyId, UploadedFile $file, int $userId): ImportPreview
    {
        $property = $this->property($propertyId);
        $before = $this->imports->importedAt($propertyId, $file->sha256);
        if ($before !== null) {
            throw new MarketRefused(MarketRefused::ALREADY_IMPORTED, '', ['date' => Days::key(Days::localDate($before, $property->timezone))]);
        }
        $read = $this->reader($property)->read($file->records);
        if ($read->hasFileError()) {
            $problem = $read->ofKind(MarketCsvProblem::FILE)[0];
            throw new MarketRefused(MarketRefused::FILE_ERROR, $problem->column, ['code' => $problem->code]);
        }
        $token = sprintf('ritmo_csv_%d_%s', $userId, bin2hex(random_bytes(8)));
        $payload = [
            'user' => $userId,
            'property' => $propertyId,
            'file' => ['name' => $file->name, 'sha256' => $file->sha256, 'bytes' => $file->bytes],
            'total' => $read->total,
            'invalid' => $read->invalidLines(),
            'warned' => count(array_unique(array_map(static fn (MarketCsvProblem $p): int => $p->line, $read->ofKind(MarketCsvProblem::WARNING)))),
            'problems' => array_map(
                static fn (MarketCsvProblem $p): array => [$p->kind, $p->code, $p->line, $p->column, $p->value],
                array_slice($read->problems, 0, ImportPreview::LISTED_PROBLEMS)
            ),
            'more_problems' => max(0, count($read->problems) - ImportPreview::LISTED_PROBLEMS),
            'rows' => array_map(static fn (ParsedRow $r): array => $r->toArray(), $read->rows),
        ];
        if (strlen((string) json_encode($payload)) > self::PREVIEW_MAX_BYTES) {
            throw new MarketRefused(MarketRefused::PREVIEW_TOO_LARGE);
        }
        $this->previews->put($token, $payload, self::PREVIEW_SECONDS);

        return $this->pending($propertyId, $token, $userId) ?? throw new MarketRefused(MarketRefused::PREVIEW_EXPIRED);
    }

    /**
     * The preview waiting for confirmation, for the screen; null when unknown, expired or of
     * another user or property.
     */
    public function pending(string $propertyId, string $token, int $userId): ?ImportPreview
    {
        $payload = $this->payload($propertyId, $token, $userId);
        if ($payload === null) {
            return null;
        }
        $rows = [];
        foreach ((array) ($payload['rows'] ?? []) as $row) {
            try {
                $rows[] = ParsedRow::fromArray((array) $row);
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        $problems = array_map(
            static fn (array $p): MarketCsvProblem => new MarketCsvProblem((string) $p[0], (string) $p[1], (int) $p[2], (string) $p[3], (string) $p[4]),
            array_values(array_filter((array) ($payload['problems'] ?? []), static fn (mixed $p): bool => is_array($p) && count($p) === 5))
        );
        $checkIns = array_map(static fn (ParsedRow $r) => $r->checkIn, $rows);
        $unknown = [];
        foreach ($problems as $problem) {
            if ($problem->code === MarketCsvProblem::UNKNOWN_COMPETITOR && $problem->value !== '') {
                $unknown[$problem->value] = true;
            }
        }

        return new ImportPreview(
            $token,
            count($rows),
            (int) ($payload['total'] ?? 0),
            (int) ($payload['invalid'] ?? 0),
            count(self::nights($rows)),
            count(array_unique(array_map(static fn (ParsedRow $r): int => $r->competitorId, $rows))),
            $checkIns === [] ? null : min($checkIns),
            $checkIns === [] ? null : max($checkIns),
            array_slice($rows, 0, ImportPreview::LISTED_ROWS),
            $problems,
            (int) ($payload['more_problems'] ?? 0),
            array_slice(array_keys($unknown), 0, 50),
        );
    }

    /**
     * Drops a preview without importing anything.
     */
    public function cancel(string $propertyId, string $token, int $userId): void
    {
        if ($this->payload($propertyId, $token, $userId) !== null) {
            $this->previews->delete($token);
        }
    }

    /**
     * Stores the previewed rows (single use: the preview is dropped whatever happens).
     *
     * @return array{import_id: int, imported: int, replaced: int, skipped: int, nights: int, anchors: int}
     *
     * @throws MarketRefused when the preview is unknown, expired, of another user or property, or has no valid row
     */
    public function commit(string $propertyId, string $token, int $userId): array
    {
        $payload = $this->payload($propertyId, $token, $userId);
        if ($payload === null) {
            throw new MarketRefused(MarketRefused::PREVIEW_EXPIRED);
        }
        $this->previews->delete($token);
        $property = $this->property($propertyId);
        $rows = $this->stillValid($property, (array) ($payload['rows'] ?? []));
        if ($rows === []) {
            throw new MarketRefused(MarketRefused::NOTHING_TO_IMPORT);
        }
        $file = (array) $payload['file'];
        if ($this->imports->importedAt($propertyId, (string) $file['sha256']) !== null) {
            throw new MarketRefused(MarketRefused::ALREADY_IMPORTED);
        }
        $now = $this->clock->now();
        $stored = $this->db->transaction(function () use ($propertyId, $userId, $file, $payload, $rows, $now): array {
            $importId = $this->imports->record($propertyId, $userId, (string) $file['name'], (string) $file['sha256'], (int) $file['bytes'], [
                'rows_total' => (int) $payload['total'],
                'rows_imported' => count($rows),
                'rows_replaced' => 0,
                'rows_invalid' => (int) $payload['invalid'],
                'rows_warned' => (int) $payload['warned'],
            ], array_map(
                static fn (array $p): array => ['line' => (int) $p[2], 'column' => (string) $p[3], 'code' => (string) $p[1]],
                array_values(array_filter((array) $payload['problems'], static fn (mixed $p): bool => is_array($p) && count($p) === 5))
            ), $now);
            $counts = $this->samples->store($propertyId, $rows, $importId);
            $this->imports->settle($importId, $counts['inserted'], $counts['replaced']);
            $this->events->record($propertyId, EditorEventRepository::MARKET_IMPORT, '', (string) count($rows), 'Market prices imported from a file', 'user:' . $userId, $now, ['import' => $importId, 'replaced' => $counts['replaced']]);

            return ['import_id' => $importId] + $counts;
        });
        $rebuilt = $this->series->rebuild($propertyId);

        return [
            'import_id' => $stored['import_id'],
            'imported' => $stored['inserted'],
            'replaced' => $stored['replaced'],
            'skipped' => count((array) $payload['rows']) - count($rows),
            'nights' => count(self::nights($rows)),
            'anchors' => $rebuilt['anchors'],
        ];
    }

    /**
     * One price typed on the market screen, read by the same rules as a file row.
     *
     * @param array{competitor: string, check_in: string, check_out: string, price: string, price_basis?: string, room?: string, available?: string} $input
     *
     * @throws MarketRefused naming the first problem of the price
     */
    public function addOne(string $propertyId, array $input, int $userId): int
    {
        $property = $this->property($propertyId);
        $header = ['competitor', 'check_in', 'check_out', 'price', 'price_basis', 'room', 'source', 'available'];
        $row = [$input['competitor'], $input['check_in'], $input['check_out'], $input['price'], $input['price_basis'] ?? '', $input['room'] ?? '', 'manual', $input['available'] ?? '1'];
        $read = $this->reader($property)->read([$header, $row]);
        $error = $read->ofKind(MarketCsvProblem::ERROR)[0] ?? null;
        if ($error !== null || $read->rows === []) {
            throw new MarketRefused(MarketRefused::BAD_PRICE, $error?->column ?? '', ['code' => $error?->code ?? MarketCsvProblem::EMPTY_FILE]);
        }
        $this->db->transaction(function () use ($propertyId, $read, $userId): void {
            $this->samples->store($propertyId, $read->rows, null);
            $this->events->record($propertyId, EditorEventRepository::MARKET_IMPORT, '', '1', 'Market price typed', 'user:' . $userId, $this->clock->now());
        });
        $this->series->rebuild($propertyId);

        return count($read->rows);
    }

    private function property(string $propertyId): Property
    {
        return $this->properties->find($propertyId) ?? throw new MarketRefused(MarketRefused::PROPERTY_UNKNOWN);
    }

    private function reader(Property $property): MarketCsvReader
    {
        $config = MarketSettings::of($this->ruleSets->current());
        $index = new CompetitorIndex(array_map(
            static fn (array $c): array => ['id' => $c['id'], 'code' => $c['code'], 'reference' => $c['reference'], 'name' => $c['name']],
            $this->competitors->forProperty($property->id, true)
        ));

        return new MarketCsvReader(new CsvContext(
            $property->id,
            $this->clock->today($property->timezone),
            $this->clock->now(),
            $property->timezone,
            $property->currency,
            $index,
            $config->csvMaxRows,
            $config->csvMaxStayNights,
            $config->maxSampleAgeDays,
        ));
    }

    /**
     * The previewed rows that still hold at commit time: the competitor is still active and the
     * stay has not started.
     *
     * @param array<int|string, mixed> $stored
     *
     * @return list<ParsedRow>
     */
    private function stillValid(Property $property, array $stored): array
    {
        $active = array_column($this->competitors->forProperty($property->id, true), 'id');
        $today = $this->clock->today($property->timezone);
        $rows = [];
        foreach ($stored as $row) {
            try {
                $parsed = ParsedRow::fromArray((array) $row);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (in_array($parsed->competitorId, $active, true) && $parsed->checkIn >= $today && $parsed->price > 0 && $parsed->currency === $property->currency) {
                $rows[] = $parsed;
            }
        }

        return $rows;
    }

    /**
     * The stored preview when it belongs to this user and property.
     *
     * @return array<string, mixed>|null
     */
    private function payload(string $propertyId, string $token, int $userId): ?array
    {
        if (preg_match('/^ritmo_csv_\d{1,18}_[0-9a-f]{16}$/', $token) !== 1 || !str_starts_with($token, 'ritmo_csv_' . $userId . '_')) {
            return null;
        }
        $payload = $this->previews->get($token);

        return $payload !== null && ($payload['user'] ?? null) === $userId && ($payload['property'] ?? null) === $propertyId ? $payload : null;
    }

    /**
     * The nights the rows cover.
     *
     * @param list<ParsedRow> $rows
     *
     * @return array<string, true>
     */
    private static function nights(array $rows): array
    {
        $nights = [];
        foreach ($rows as $row) {
            for ($i = 0; $i < $row->nights; ++$i) {
                $nights[Days::key(Days::add($row->checkIn, $i))] = true;
            }
        }

        return $nights;
    }
}
