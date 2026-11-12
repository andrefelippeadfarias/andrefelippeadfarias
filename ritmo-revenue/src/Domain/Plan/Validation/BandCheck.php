<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Validate\BandIssue;
use Ritmo\Domain\Relations\Validate\DiscountBandCheck;
use Ritmo\Domain\Relations\Validate\FreshNight;
use Ritmo\Domain\Relations\Validate\PlanNightValues;
use Ritmo\Domain\Support\Days;

/**
 * The discount bands of the relations over the whole plan, in currency after the floors were
 * turned into whole percentages: the value of each room night is its floor as the pricing system
 * applies it, its ceiling likewise. A band rule broken on a night is reported on the item of that
 * room night when there is one, else on the plan with the room and night.
 *
 * Reference: validar_plano.Validador.validar_balcony, through Relations\Validate\DiscountBandCheck
 * (which accepts the night the coherence stage left at the switched price, switch_satisfied).
 */
final class BandCheck
{
    public function __construct(
        private readonly DiscountBandCheck $bands,
        private readonly ?RelationReport $report,
        private readonly ?FreshCalendar $calendar,
        private readonly IssueLog $log,
    ) {
    }

    /**
     * @param list<ItemFacts> $facts the items that passed the shape checks
     */
    public function check(array $facts): void
    {
        $planned = [];
        $ceilings = [];
        $orders = [];
        $indexes = [];
        foreach ($facts as $f) {
            $key = $f->item->key();
            $indexes[$key] = $f->index;
            if ($f->floor() !== null) {
                $planned[$key] = $f->floor();
                if ($f->authority->isValid()) {
                    $orders[$key] = (string) $f->authority->id();
                }
            }
            if ($f->percent->maxAmount() !== null) {
                $ceilings[$key] = $f->percent->maxAmount();
            }
        }
        $values = new PlanNightValues($planned, $ceilings, array_keys($planned), $orders, $this->fresh());
        foreach ($this->bands->check($values, $this->report) as $issue) {
            $this->report($issue, $indexes);
        }
    }

    /**
     * @param array<string, int> $indexes room night key => item position
     */
    private function report(BandIssue $issue, array $indexes): void
    {
        $key = $issue->room . '|' . Days::key($issue->night);
        $index = $indexes[$key] ?? null;
        $this->log->add(
            $issue->isError,
            str_replace('.', '_', $issue->code),
            sprintf('Relation %s: %s on %s (%.2f against %.2f).', $issue->relationId, str_replace(['band.', '_'], ['', ' '], $issue->code), Days::key($issue->night), (float) ($issue->args['value'] ?? 0.0), (float) ($issue->args['reference'] ?? 0.0)),
            $index,
            $issue->room,
            $issue->night,
            ['relation' => $issue->relationId] + $issue->args,
            $index === null ? IssueSubject::Plan : IssueSubject::Item,
        );
    }

    /**
     * @return array<string, FreshNight>
     */
    private function fresh(): array
    {
        $fresh = [];
        foreach ($this->calendar?->nights() ?? [] as $key => $night) {
            $fresh[$key] = new FreshNight($night->price, $night->free(), $night->sold, $night->total);
        }

        return $fresh;
    }
}
