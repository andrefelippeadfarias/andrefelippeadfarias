<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Application\Report\ReportScope;
use Ritmo\Application\Report\ReportService;
use Ritmo\Application\Report\ReportSettings;
use Ritmo\Application\Report\Store\RevenueTargetStore;
use Ritmo\Application\Report\Store\SnapshotStore;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\WordPress\Services;

/**
 * The reports area's services over the plugin's container (built once per request).
 */
final class ReportsServices
{
    private static ?self $instance = null;

    private ?ReportService $service = null;

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

    public function scope(Property $property): ReportScope
    {
        return new ReportScope($property, $this->services->roomTypes()->forProperty($property->id));
    }

    public function reports(): ReportService
    {
        $rules = $this->services->ruleSets()->current() ?? RuleSet::defaults();

        return $this->service ??= new ReportService(
            new BookingRepository($this->services->db()),
            $this->services->snapshots(),
            $this->targetStore(),
            new SnapshotStore($this->services->db()),
            $rules,
            self::config($rules),
        );
    }

    public function targetStore(): RevenueTargetStore
    {
        return new RevenueTargetStore($this->services->db());
    }

    /**
     * The section as configured; neutral when it does not read (the editor shows the problem).
     */
    public static function config(RuleSet $rules): ReportsConfig
    {
        try {
            return ReportSettings::of($rules);
        } catch (InvalidConfig) {
            return ReportsConfig::neutral();
        }
    }
}
