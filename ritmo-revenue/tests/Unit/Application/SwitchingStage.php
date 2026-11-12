<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use Closure;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\Stage;
use Ritmo\Application\ShadowRun\StageResult;

/**
 * Runs a stage and then calls a hook, to change the world between two stages of one request.
 */
final class SwitchingStage implements Stage
{
    public function __construct(private readonly Stage $inner, private readonly Closure $after)
    {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function run(RunContext $context): StageResult
    {
        $result = $this->inner->run($context);
        ($this->after)();

        return $result;
    }
}
