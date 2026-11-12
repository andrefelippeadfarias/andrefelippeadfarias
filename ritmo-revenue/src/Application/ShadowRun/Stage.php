<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

/**
 * One short step of a run. A stage reads and computes; whatever it stores goes in the
 * persist closure of its result, which the pipeline runs in the same transaction as the marker
 * that says the stage completed. A stage that fails leaves nothing behind and runs again when
 * the run resumes.
 */
interface Stage
{
    /**
     * Name recorded in the run ("collect", "measure", ...); at most 16 characters.
     */
    public function name(): string;

    public function run(RunContext $context): StageResult;
}
