<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * What the assistant is asked to do. Each purpose has its own fixed system prompt
 * (PromptLibrary) and is recorded with the usage of the call.
 */
enum AiPurpose: string
{
    case WeeklyReport = 'weekly_report';
    case RunReport = 'run_report';
    case AlertExplanation = 'alert_explanation';
    case RequestDraft = 'request_draft';
}
