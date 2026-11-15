<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\WordPress;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ritmo\WordPress\TimeLimit;

/**
 * The tick's time comes from the rule schedule.tick_budget_seconds and never passes what PHP gives
 * the request; the engine asks WordPress for its larger memory limit.
 */
final class TimeLimitTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTickBudgetFollowsTheRule(): void
    {
        ini_set('max_execution_time', '0');
        self::assertSame(25, TimeLimit::tickBudget(25), 'lowering the rule shortens the tick');
        self::assertSame(40, TimeLimit::tickBudget(40));
        self::assertSame(5, TimeLimit::tickBudget(1), 'never less than 5 seconds');
        $plugin = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Plugin.php');
        self::assertStringContainsString('TimeLimit::tickBudget($stack->rules()->schedule()->tickBudgetSeconds)', $plugin, 'the tick reads the rule');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnderAShortPhpLimitTheTickAsksForMoreTimeOrStaysUnderIt(): void
    {
        ini_set('max_execution_time', '30');
        $budget = TimeLimit::tickBudget(40);
        $limit = (int) ini_get('max_execution_time');
        self::assertTrue($budget === 40 && ($limit === 0 || $limit >= 45) || $budget <= 25, "budget $budget under the PHP limit $limit");
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheEngineAsksWordPressForMoreMemory(): void
    {
        $GLOBALS['ritmo_raised'] = [];
        eval('function wp_raise_memory_limit($context = "admin") { $GLOBALS["ritmo_raised"][] = $context; return "256M"; }');
        TimeLimit::forEngine();
        TimeLimit::forEngine('cron');
        self::assertSame(['admin', 'cron'], $GLOBALS['ritmo_raised']);
    }
}
