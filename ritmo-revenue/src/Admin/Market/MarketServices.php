<?php

declare(strict_types=1);

namespace Ritmo\Admin\Market;

use Closure;
use Ritmo\Admin\Editors\EditorServices;
use Ritmo\Application\Market\CompetitorService;
use Ritmo\Application\Market\MarketBoard;
use Ritmo\Application\Market\MarketExport;
use Ritmo\Application\Market\MarketFreshness;
use Ritmo\Application\Market\MarketImportService;
use Ritmo\Application\Market\MarketReminder;
use Ritmo\Application\Market\MarketRulerAssembler;
use Ritmo\Application\Market\MarketSeriesService;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\LadderReferenceRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketImportRepository;
use Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;
use Ritmo\WordPress\Services;

/**
 * The market use cases and read models for one request, built from the plugin's services.
 */
final class MarketServices
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $made = [];

    private function __construct(private readonly Services $services)
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(Services::instance());
    }

    public function services(): Services
    {
        return $this->services;
    }

    public function series(): MarketSeriesService
    {
        return $this->once('series', fn (): MarketSeriesService => new MarketSeriesService(
            $this->services->db(),
            $this->services->clock(),
            $this->services->properties(),
            $this->services->ruleSets(),
            $this->competitors(),
            $this->samples(),
            new MarketSeriesRepository($this->services->db()),
            new MarketAnchorRepository($this->services->db(), $this->services->clock()),
        ));
    }

    public function import(): MarketImportService
    {
        return $this->once('import', fn (): MarketImportService => new MarketImportService(
            $this->services->db(),
            $this->services->clock(),
            $this->services->properties(),
            $this->services->ruleSets(),
            $this->competitors(),
            $this->samples(),
            new MarketImportRepository($this->services->db()),
            EditorServices::instance()->events(),
            $this->series(),
            new TransientPreviewStore(),
        ));
    }

    public function competitorService(): CompetitorService
    {
        return $this->once('competitor_service', fn (): CompetitorService => new CompetitorService(
            $this->services->db(),
            $this->services->clock(),
            $this->services->properties(),
            $this->competitors(),
            EditorServices::instance()->events(),
            $this->series(),
        ));
    }

    public function board(): MarketBoard
    {
        return $this->once('board', fn (): MarketBoard => new MarketBoard(
            $this->services->clock(),
            $this->competitors(),
            $this->samples(),
            new MarketSeriesRepository($this->services->db()),
            new MarketAnchorRepository($this->services->db(), $this->services->clock()),
            new MarketImportRepository($this->services->db()),
        ));
    }

    public function export(): MarketExport
    {
        return $this->once('export', fn (): MarketExport => new MarketExport(
            $this->competitors(),
            $this->samples(),
            new MarketSeriesRepository($this->services->db()),
            new MarketAnchorRepository($this->services->db(), $this->services->clock()),
        ));
    }

    public function freshness(): MarketFreshness
    {
        return $this->once('freshness', fn (): MarketFreshness => new MarketFreshness($this->samples()));
    }

    public function reminder(): MarketReminder
    {
        return $this->once('reminder', fn (): MarketReminder => new MarketReminder(
            $this->freshness(),
            new MarketRulerAssembler(new MarketSeriesRepository($this->services->db()), new MarketAnchorRepository($this->services->db(), $this->services->clock()), new LadderReferenceRepository($this->services->db())),
        ));
    }

    public function uploads(): CsvUploadReader
    {
        return new CsvUploadReader(
            static fn (string $tmp): bool => is_uploaded_file($tmp),
            static function (string $tmp, string $name): bool {
                $type = wp_check_filetype_and_ext($tmp, $name, ['csv' => 'text/csv', 'txt' => 'text/plain']);

                return ($type['ext'] ?? false) === 'csv';
            },
        );
    }

    private function competitors(): CompetitorRepository
    {
        return $this->once('competitors', fn (): CompetitorRepository => new CompetitorRepository($this->services->db(), $this->services->clock()));
    }

    private function samples(): StaySampleRepository
    {
        return $this->once('samples', fn (): StaySampleRepository => new StaySampleRepository($this->services->db()));
    }

    /**
     * @template T of object
     *
     * @param Closure(): T $make
     *
     * @return T
     */
    private function once(string $key, Closure $make): object
    {
        return $this->made[$key] ??= $make();
    }
}
