<?php

declare(strict_types=1);

namespace Ritmo\Rest\Ai;

use Closure;
use InvalidArgumentException;
use Ritmo\Application\Ai\AiAssistant;
use Ritmo\Application\Ai\AiDraftQueue;
use Ritmo\Domain\Ai\AiModelInfo;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Support\Num;

/**
 * The answers of the assistant's routes of ritmo/v1, as [status, body]. Every answer is a
 * sentence or a checked text, never an internal name or the prompt: a stop answers 409 (the
 * settings stop it: off, no key, cap, model), 422 (the answer or the request was not accepted)
 * or 502 (Claude failed), with the reason in words.
 */
final class AiEndpoints
{
    /**
     * @param Closure(AiStopped): array{message: string, details: list<string>} $words
     * @param Closure(string, string): ?array{data: array<string, mixed>, context: \Ritmo\Domain\Ai\DraftContext, property: string} $requestInput property id and text
     * @param Closure(string): ?array{about: array<string, mixed>, facts: list<array{label: string, value: string}>, rooms?: list<string>} $weeklyInput property id
     * @param Closure(int): ?array{about: array<string, mixed>, facts: list<array{label: string, value: string}>, rooms?: list<string>} $runInput run id
     * @param Closure(int): ?array{alert: array<string, mixed>} $alertInput alert id
     * @param Closure(string, string, string, \Ritmo\Domain\Ai\AiDraft, string): array<string, mixed> $draftView id, request, created at, draft, property id
     * @param Closure(string): string $notFound what was not found (property, run, alert) => a sentence
     */
    public function __construct(
        private readonly AiAssistant $assistant,
        private readonly AiDraftQueue $drafts,
        private readonly Closure $words,
        private readonly Closure $requestInput,
        private readonly Closure $weeklyInput,
        private readonly Closure $runInput,
        private readonly Closure $alertInput,
        private readonly Closure $draftView,
        private readonly Closure $notFound,
        private readonly string $locale,
        private readonly int $userId,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function status(): array
    {
        $status = $this->assistant->status();
        $blocker = $status->blocker();

        return [200, [
            'ready' => $blocker === null,
            'reason' => $blocker,
            'message' => $blocker === null ? '' : ($this->words)(new AiStopped($blocker, ''))['message'],
            'model' => $status->settings->model,
            'spent_usd' => Num::roundHalfEven($status->usage->spentUsd(), 4),
            'cap_usd' => $status->settings->monthlyCapUsd,
        ]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function draft(string $propertyId, string $text): array
    {
        $input = ($this->requestInput)($propertyId, $text);
        if ($input === null) {
            return $this->missing('property');
        }
        try {
            $draft = $this->assistant->draft($text, $input['data'], $input['context'], $this->locale);
        } catch (AiStopped $e) {
            return $this->stopped($e);
        } catch (InvalidArgumentException) {
            return [400, ['code' => 'request_length', 'message' => ($this->notFound)('request_length')]];
        }
        $id = $this->drafts->add($input['property'], $this->userId, $text, $draft);
        $row = $this->drafts->find($id);

        return [201, ['draft' => ($this->draftView)($id, $text, (string) ($row['created_at'] ?? ''), $draft, $input['property'])]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function weekly(string $propertyId): array
    {
        $input = ($this->weeklyInput)($propertyId);

        return $input === null ? $this->missing('property') : $this->report(AiPurpose::WeeklyReport, $input);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function runReport(int $runId): array
    {
        $input = ($this->runInput)($runId);

        return $input === null ? $this->missing('run') : $this->report(AiPurpose::RunReport, $input);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function explainAlert(int $alertId): array
    {
        $input = ($this->alertInput)($alertId);
        if ($input === null) {
            return $this->missing('alert');
        }
        try {
            $answer = $this->assistant->explainAlert($input['alert'], $this->locale);
        } catch (AiStopped $e) {
            return $this->stopped($e);
        }

        return [200, $answer];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function test(): array
    {
        try {
            $models = $this->assistant->testConnection();
        } catch (AiStopped $e) {
            return $this->stopped($e);
        }

        return [200, ['models' => array_map(static fn (AiModelInfo $m): array => ['id' => $m->id, 'name' => $m->displayName], $models)]];
    }

    /**
     * @param array{about: array<string, mixed>, facts: list<array{label: string, value: string}>} $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function report(AiPurpose $purpose, array $input): array
    {
        try {
            $text = $this->assistant->reportText($purpose, $input['about'], $input['facts'], $this->locale, array_values(array_filter((array) ($input['rooms'] ?? []), 'is_string')));
        } catch (AiStopped $e) {
            return $this->stopped($e);
        }

        return [200, ['text' => $text->text, 'model' => $text->model, 'fallback' => $text->fallback]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function missing(string $what): array
    {
        return [404, ['code' => 'not_found', 'message' => ($this->notFound)($what)]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function stopped(AiStopped $e): array
    {
        $status = match ($e->reason) {
            AiStopped::DISABLED, AiStopped::LICENCE, AiStopped::NO_KEY, AiStopped::BUDGET, AiStopped::MODEL_UNAVAILABLE, AiStopped::CONFIG => 409,
            AiStopped::PROVIDER => 502,
            default => 422,
        };
        $words = ($this->words)($e);

        return [$status, ['code' => $e->reason, 'message' => $words['message'], 'details' => $words['details']]];
    }
}
