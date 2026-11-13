<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * A saved review: the day it was taken and, per month and property, the sold revenue and the
 * average sent price (totals only, never a booking).
 */
final class ReviewSnapshot
{
    /**
     * @param array<string, array<string, array{sold: float, price: float|null}>> $rows month => property id => totals
     */
    public function __construct(public readonly DateTimeImmutable $day, public readonly array $rows)
    {
    }

    public static function of(ReviewResult $result): self
    {
        $rows = [];
        foreach ($result->rows as $row) {
            $rows[$row->month][$row->propertyId] = ['sold' => $row->soldRevenue, 'price' => $row->averagePrice];
        }

        return new self($result->today, $rows);
    }

    /**
     * @return array{sold: float, price: float|null}|null
     */
    public function row(string $month, string $propertyId): ?array
    {
        return $this->rows[$month][$propertyId] ?? null;
    }

    /**
     * @return array<string, mixed> the stored form
     */
    public function toArray(): array
    {
        return ['day' => Days::key($this->day), 'rows' => $this->rows];
    }

    /**
     * @param array<string, mixed> $data the stored form
     *
     * @throws InvalidArgumentException on a malformed snapshot
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['day'] ?? null) || !Days::isValid($data['day']) || !is_array($data['rows'] ?? null)) {
            throw new InvalidArgumentException('A review snapshot needs its day and rows.');
        }
        $rows = [];
        foreach ($data['rows'] as $month => $properties) {
            foreach ((array) $properties as $property => $row) {
                $rows[(string) $month][(string) $property] = [
                    'sold' => (float) ($row['sold'] ?? 0),
                    'price' => isset($row['price']) && is_numeric($row['price']) ? (float) $row['price'] : null,
                ];
            }
        }

        return new self(Days::date($data['day']), $rows);
    }
}
