<?php

declare(strict_types=1);

namespace Ritmo\Application\Report\Store;

use InvalidArgumentException;
use Ritmo\Domain\Report\Month\MonthTarget;
use Ritmo\Domain\Report\Review\MarketMonth;
use Ritmo\Domain\Report\Targets\TargetInputs;

/**
 * What the owner typed for one property and one month (table revenue_targets), as the reports
 * read it.
 */
final class TargetRow
{
    /** The editable fields, as the form, the REST route and the table name them. */
    public const FIELDS = [
        'minimum', 'realistic', 'market_occupancy_ly', 'market_adr_ly', 'occupancy_cap',
        'adjust_floor', 'adjust_base', 'adjust_goal', 'market_sold_percent', 'market_last_year_percent',
    ];

    /**
     * @param array<string, float|null> $values field => value (null: not typed)
     *
     * @throws InvalidArgumentException on an unknown field or a value outside its range
     */
    public function __construct(public readonly string $month, public readonly array $values)
    {
        if (array_diff(array_keys($values), self::FIELDS) !== []) {
            throw new InvalidArgumentException('Unknown target field.');
        }
        $this->inputs();
        $this->target();
        foreach (['market_sold_percent', 'market_last_year_percent'] as $percent) {
            $v = $values[$percent] ?? null;
            if ($v !== null && ($v < 0 || $v > 100)) {
                throw new InvalidArgumentException('A market occupancy is a percentage from 0 to 100.');
            }
        }
    }

    public function value(string $field): ?float
    {
        return $this->values[$field] ?? null;
    }

    public function inputs(): TargetInputs
    {
        return new TargetInputs(
            $this->value('market_occupancy_ly'),
            $this->value('market_adr_ly'),
            $this->value('occupancy_cap') ?? 1.0,
            ['floor' => $this->value('adjust_floor') ?? 0.0, 'base' => $this->value('adjust_base') ?? 0.0, 'goal' => $this->value('adjust_goal') ?? 0.0],
            $this->value('minimum'),
            $this->value('realistic'),
        );
    }

    public function target(): MonthTarget
    {
        return new MonthTarget($this->value('minimum'), $this->value('realistic'));
    }

    public function market(): ?MarketMonth
    {
        $sold = $this->value('market_sold_percent');
        $lastYear = $this->value('market_last_year_percent');

        return $sold === null && $lastYear === null ? null : new MarketMonth($sold, $lastYear);
    }
}
