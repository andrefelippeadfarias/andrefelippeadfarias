<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Closure;
use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\Rules\ApprovalServiceAdapter;
use Ritmo\Application\Rules\Calendar\HolidayCatalogue;
use Ritmo\Application\Rules\Orders\OrderBoard;
use Ritmo\Application\Rules\Orders\OrderDesk;
use Ritmo\Application\Rules\RuleChecks;
use Ritmo\Application\Rules\RuleSetEditor;
use Ritmo\Application\Rules\VersionHistory;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\OrderLedgerRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\WordPress\Services;
use Ritmo\WordPress\WpCapabilityCheck;

/**
 * The use cases of the rules and orders screens for one request, built from the plugin's
 * services. Nothing here reaches an external system.
 */
final class EditorServices
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

    public function editor(): RuleSetEditor
    {
        return $this->once('editor', fn (): RuleSetEditor => new RuleSetEditor(
            $this->services->db(),
            $this->services->clock(),
            $this->services->ruleSets(),
            $this->services->properties(),
            $this->events(),
            $this->checks(),
            new ApprovalServiceAdapter(new ApprovalService(new PlanApprovalRepository($this->services->db()), new WpCapabilityCheck(), $this->services->clock()), new PlanApprovalRepository($this->services->db())),
        ));
    }

    public function checks(): RuleChecks
    {
        return $this->once('checks', fn (): RuleChecks => new RuleChecks($this->services->properties(), $this->services->roomTypes()));
    }

    public function history(): VersionHistory
    {
        return $this->once('history', fn (): VersionHistory => new VersionHistory($this->services->ruleSets(), $this->events(), $this->services->properties()));
    }

    public function drafts(): DraftDesk
    {
        return $this->once('drafts', fn (): DraftDesk => new DraftDesk(new UserMetaDraftStorage(), $this->services->ruleSets(), $this->services->clock()));
    }

    public function orders(): OrderDesk
    {
        return $this->once('orders', fn (): OrderDesk => new OrderDesk(
            $this->services->db(),
            $this->services->clock(),
            $this->services->properties(),
            $this->services->roomTypes(),
            $this->services->ruleSets(),
            new OrderRepository($this->services->db(), $this->services->clock()),
            new OrderLedgerRepository($this->services->db()),
            new DetectedFreezeRepository($this->services->db()),
            $this->events(),
            new OperationEventRepository($this->services->db()),
        ));
    }

    public function orderBoard(): OrderBoard
    {
        return $this->once('order_board', fn (): OrderBoard => new OrderBoard($this->services->clock(), new OrderLedgerRepository($this->services->db()), new DetectedFreezeRepository($this->services->db())));
    }

    public function holidays(): HolidayCatalogue
    {
        return $this->once('holidays', static fn (): HolidayCatalogue => new HolidayCatalogue(dirname(__DIR__, 3) . '/presets/holidays'));
    }

    public function events(): EditorEventRepository
    {
        return $this->once('events', fn (): EditorEventRepository => new EditorEventRepository($this->services->db()));
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
