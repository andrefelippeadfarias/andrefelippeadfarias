<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\PlanIssue;

/**
 * The errors and warnings found while validating one plan, in the order they were found.
 */
final class IssueLog
{
    /** @var list<PlanIssue> */
    private array $errors = [];

    /** @var list<PlanIssue> */
    private array $warnings = [];

    /**
     * @param array<string, scalar|null> $params
     */
    public function item(ItemFacts $f, bool $error, string $code, string $message, array $params = []): void
    {
        $this->add($error, $code, $message, $f->index, $f->item->roomCode, $f->item->date, $params, IssueSubject::Item);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function add(bool $error, string $code, string $message, ?int $index, ?string $room, ?DateTimeImmutable $date, array $params, IssueSubject $subject): void
    {
        $issue = new PlanIssue($error ? PlanIssue::ERROR : PlanIssue::WARNING, $code, $message, $index, $room, $date, $params, $subject);
        if ($error) {
            $this->errors[] = $issue;
        } else {
            $this->warnings[] = $issue;
        }
    }

    /**
     * @return list<PlanIssue>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<PlanIssue>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
