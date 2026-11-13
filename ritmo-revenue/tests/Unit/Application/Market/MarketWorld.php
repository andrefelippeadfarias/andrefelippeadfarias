<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Market;

use Ritmo\Application\Market\CompetitorService;
use Ritmo\Application\Market\MarketBoard;
use Ritmo\Application\Market\MarketExport;
use Ritmo\Application\Market\MarketImportService;
use Ritmo\Application\Market\MarketRulerAssembler;
use Ritmo\Application\Market\MarketSeriesService;
use Ritmo\Application\Market\PreviewStore;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\LadderReferenceRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketImportRepository;
use Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;

/**
 * A property on an in-memory SQLite database with every table up to the market draft migration,
 * and the market services wired as the integration would wire them. The frozen now is
 * 2026-11-13 13:00 UTC (10:00 at the property, UTC-3).
 */
final class MarketWorld
{
    public const PROPERTY = 'inn-1';

    public readonly PdoDatabase $db;
    public readonly MovableClock $clock;
    public readonly CompetitorRepository $competitors;
    public readonly StaySampleRepository $samples;
    public readonly MarketSeriesRepository $series;
    public readonly MarketAnchorRepository $anchors;
    public readonly MarketImportRepository $imports;
    public readonly EditorEventRepository $events;
    public readonly LadderReferenceRepository $references;
    public readonly RuleSetRepository $ruleSets;
    public readonly MarketSeriesService $seriesService;
    public readonly MarketImportService $importService;
    public readonly CompetitorService $competitorService;
    public readonly MarketBoard $board;
    public readonly MarketExport $export;
    public readonly MarketRulerAssembler $ruler;
    public readonly PreviewStore $previews;

    public function __construct(?RuleSet $rules = null)
    {
        $this->db = PdoDatabase::inMemory();
        (new Migrator($this->db, new ArrayStore(), Migrations::all()))->migrate();
        $this->clock = new MovableClock('2026-11-13T13:00:00Z');
        $properties = new PropertyRepository($this->db, $this->clock);
        $properties->save(new Property(self::PROPERTY, 'Inn One', 'America/Sao_Paulo', 'BRL'));
        $this->ruleSets = new RuleSetRepository($this->db, $this->clock);
        $this->ruleSets->save($rules ?? RuleSet::defaults());
        $this->competitors = new CompetitorRepository($this->db, $this->clock);
        $this->samples = new StaySampleRepository($this->db);
        $this->series = new MarketSeriesRepository($this->db);
        $this->anchors = new MarketAnchorRepository($this->db, $this->clock);
        $this->imports = new MarketImportRepository($this->db);
        $this->events = new EditorEventRepository($this->db);
        $this->references = new LadderReferenceRepository($this->db);
        $this->previews = new class () implements PreviewStore {
            /** @var array<string, array<string, mixed>> */
            public array $stored = [];

            public function put(string $token, array $payload, int $ttlSeconds): void
            {
                $this->stored[$token] = json_decode((string) json_encode($payload), true);
            }

            public function get(string $token): ?array
            {
                return $this->stored[$token] ?? null;
            }

            public function delete(string $token): void
            {
                unset($this->stored[$token]);
            }
        };
        $this->seriesService = new MarketSeriesService($this->db, $this->clock, $properties, $this->ruleSets, $this->competitors, $this->samples, $this->series, $this->anchors);
        $this->importService = new MarketImportService($this->db, $this->clock, $properties, $this->ruleSets, $this->competitors, $this->samples, $this->imports, $this->events, $this->seriesService, $this->previews);
        $this->competitorService = new CompetitorService($this->db, $this->clock, $properties, $this->competitors, $this->events, $this->seriesService);
        $this->board = new MarketBoard($this->clock, $this->competitors, $this->samples, $this->series, $this->anchors, $this->imports);
        $this->export = new MarketExport($this->competitors, $this->samples, $this->series, $this->anchors);
        $this->ruler = new MarketRulerAssembler($this->series, $this->anchors, $this->references);
    }

    /**
     * Adds competitors comp-01 ... comp-NN (the first $peers tagged peer) and returns their ids.
     *
     * @return list<int>
     */
    public function addCompetitors(int $count, int $peers = 0): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; ++$i) {
            $ids[] = $this->competitorService->save(self::PROPERTY, null, ['name' => sprintf('Competitor %02d', $i), 'code' => sprintf('comp-%02d', $i), 'tags' => $i <= $peers ? 'peer' : ''], 7);
        }

        return $ids;
    }

    public function count(string $table): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_' . $table);
    }
}
