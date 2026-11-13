<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Closure;
use Ritmo\Application\Dashboard\Alerts\AlertList;
use Ritmo\Application\Dashboard\Changes\ChangeList;
use Ritmo\Application\Dashboard\Connections\ConnectionTests;
use Ritmo\Application\Dashboard\Overview\OverviewQuery;
use Ritmo\Application\Dashboard\Setup\SetupState;
use Ritmo\Application\Dashboard\Setup\SetupWizard;
use Ritmo\Application\Dashboard\Setup\WouldDo;
use Ritmo\Application\Dashboard\StatusQuery;
use Ritmo\Application\Dashboard\Todo\TodoGenerator;
use Ritmo\Application\Dashboard\Todo\TodoService;
use Ritmo\Application\Dashboard\Todo\TodoStore;
use Ritmo\Application\Grid\Approvals\ApprovalBoard;
use Ritmo\Application\Grid\LastPlan;
use Ritmo\Application\Grid\NightDetailQuery;
use Ritmo\Application\Grid\NightGridQuery;
use Ritmo\Infrastructure\Db\Query\Screens\AlertListReader;
use Ritmo\Infrastructure\Db\Query\Screens\ChangeLogReader;
use Ritmo\Infrastructure\Db\Query\Screens\TodoRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;
use Ritmo\WordPress\Services;
use Ritmo\WordPress\WpCapabilityCheck;

/**
 * Builds the read models and use cases of the central screens for one request, from the
 * plugin's services (the composition root keeps the repositories). Read models only read.
 */
final class ScreenServices
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

    public function lastPlan(): LastPlan
    {
        return $this->once('last_plan', fn (): LastPlan => new LastPlan($this->services->runs(), $this->services->planItems()));
    }

    public function grid(): NightGridQuery
    {
        $stack = $this->services->stack();

        return $this->once('grid', fn (): NightGridQuery => new NightGridQuery(
            $this->services->properties(),
            $this->services->roomTypes(),
            $this->services->ruleSets(),
            $this->services->snapshots(),
            $stack->bookings(),
            $this->lastPlan(),
            $stack->freezes(),
            new OrderRepository($this->services->db(), $this->services->clock()),
            $this->services->alerts(),
            $stack->changeLog(),
            $this->services->clock(),
        ));
    }

    public function nightDetail(): NightDetailQuery
    {
        $stack = $this->services->stack();

        return $this->once('night_detail', fn (): NightDetailQuery => new NightDetailQuery(
            $this->grid(),
            $this->services->roomTypes(),
            $stack->bookings(),
            $stack->freezes(),
            $stack->changeLog(),
            new StepLedgerRepository($this->services->db()),
            $this->services->planItems(),
        ));
    }

    public function approvals(): ApprovalBoard
    {
        $stack = $this->services->stack();

        return $this->once('approvals', fn (): ApprovalBoard => new ApprovalBoard(
            $this->services->properties(),
            $this->services->roomTypes(),
            $this->services->ruleSets(),
            $stack->approvalRequests(),
            $this->services->planItems(),
            $stack->holds(),
            $this->services->clock(),
        ));
    }

    public function status(): StatusQuery
    {
        $stack = $this->services->stack();

        return $this->once('status', fn (): StatusQuery => new StatusQuery($this->services->properties(), $this->services->emergencySwitch(), $stack->states(), $stack->approvalRequests(), $this->services->runs(), $this->services->clock()));
    }

    public function alerts(): AlertList
    {
        return $this->once('alert_list', fn (): AlertListReader => new AlertListReader($this->services->db()));
    }

    public function changes(): ChangeList
    {
        return $this->once('change_list', fn (): ChangeLogReader => new ChangeLogReader($this->services->db(), $this->services->stack()->changeLog()));
    }

    public function todoStore(): TodoStore
    {
        return $this->once('todo_store', fn (): TodoRepository => new TodoRepository($this->services->db()));
    }

    public function todos(): TodoService
    {
        return $this->once('todos', fn (): TodoService => new TodoService($this->todoStore(), $this->services->clock()));
    }

    public function todoGenerator(): TodoGenerator
    {
        return $this->once('todo_generator', fn (): TodoGenerator => new TodoGenerator($this->todoStore(), $this->services->store(), $this->services->clock()));
    }

    public function overview(): OverviewQuery
    {
        return $this->once('overview', fn (): OverviewQuery => new OverviewQuery(
            $this->services->properties(),
            $this->services->roomTypes(),
            $this->services->ruleSets(),
            $this->services->snapshots(),
            $this->services->stack()->bookings(),
            $this->services->occupancy(),
            $this->lastPlan(),
            $this->status(),
            $this->alerts(),
            $this->todoStore(),
            $this->services->clock(),
        ));
    }

    public function connectionTests(): ConnectionTests
    {
        return $this->once('connection_tests', fn (): ConnectionTests => new ConnectionTests($this->services->store(), $this->services->clock()));
    }

    public function setupState(): SetupState
    {
        return $this->once('setup_state', fn (): SetupState => new SetupState($this->services->store()));
    }

    public function wizard(): SetupWizard
    {
        return $this->once('wizard', fn (): SetupWizard => new SetupWizard(
            $this->services->db(),
            $this->services->properties(),
            $this->services->roomTypes(),
            $this->services->ruleSets(),
            $this->services->runs(),
            $this->services->setup(),
            $this->connectionTests(),
            $this->setupState(),
            new WpCapabilityCheck(),
            $this->services->clock(),
        ));
    }

    public function wouldDo(): WouldDo
    {
        return $this->once('would_do', fn (): WouldDo => new WouldDo($this->lastPlan()));
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
