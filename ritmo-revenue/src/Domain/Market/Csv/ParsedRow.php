<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Market\Series\StaySample;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * One valid row of a market price file: one competitor's price for one stay, ready to be stored.
 * Holds no guest data by construction (the file's other columns are never read).
 */
final class ParsedRow
{
    public readonly DateTimeImmutable $checkIn;

    /**
     * @param float      $price per-night price, to the cent (total / nights when a total was given)
     * @param float|null $total the stay total as given; null when the row gave a per-night price
     */
    public function __construct(
        public readonly int $line,
        public readonly int $competitorId,
        DateTimeImmutable $checkIn,
        public readonly int $nights,
        public readonly RoomBasis $basis,
        public readonly float $price,
        public readonly ?float $total,
        public readonly string $currency,
        public readonly DateTimeImmutable $capturedAt,
        public readonly string $source,
        public readonly bool $available,
        public readonly string $dedupKey,
    ) {
        $this->checkIn = Days::normalize($checkIn);
    }

    /**
     * The key a stored row is replaced by: property, competitor, stay, basis, source and the
     * capture to the minute.
     */
    public static function dedupKey(string $propertyId, int $competitorId, DateTimeImmutable $checkIn, int $nights, RoomBasis $basis, string $source, DateTimeImmutable $capturedAt): string
    {
        return hash('sha256', implode('|', [$propertyId, $competitorId, Days::key($checkIn), $nights, $basis->value, $source, $capturedAt->format('Y-m-d H:i')]));
    }

    public function checkOut(): DateTimeImmutable
    {
        return Days::add($this->checkIn, $this->nights);
    }

    public function toSample(int $sequence = 0): StaySample
    {
        return new StaySample($this->competitorId, $this->checkIn, $this->nights, $this->basis, $this->price, $this->total, $this->capturedAt, $this->available, $sequence);
    }

    /**
     * Plain values for a short-lived preview (a transient), read back by fromArray().
     *
     * @return array<string, int|float|string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line, 'competitor_id' => $this->competitorId, 'check_in' => Days::key($this->checkIn),
            'nights' => $this->nights, 'basis' => $this->basis->value, 'price' => $this->price, 'total' => $this->total,
            'currency' => $this->currency, 'captured_at' => $this->capturedAt->format('Y-m-d\TH:i:s\Z'),
            'source' => $this->source, 'available' => $this->available, 'dedup_key' => $this->dedupKey,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when the row is not one written by toArray()
     */
    public static function fromArray(array $row): self
    {
        $basis = RoomBasis::tryFrom((string) ($row['basis'] ?? ''));
        $checkIn = (string) ($row['check_in'] ?? '');
        $captured = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', (string) ($row['captured_at'] ?? ''), new DateTimeZone('UTC'));
        $price = $row['price'] ?? null;
        if ($basis === null || !Days::isValid($checkIn) || $captured === false || (!is_float($price) && !is_int($price))) {
            throw new InvalidArgumentException('A previewed row cannot be read back.');
        }
        $total = $row['total'] ?? null;

        return new self(
            (int) ($row['line'] ?? 0),
            (int) ($row['competitor_id'] ?? 0),
            Days::date($checkIn),
            (int) ($row['nights'] ?? 0),
            $basis,
            Num::roundHalfEven((float) $price, 2),
            is_int($total) || is_float($total) ? (float) $total : null,
            (string) ($row['currency'] ?? ''),
            $captured,
            (string) ($row['source'] ?? ''),
            (bool) ($row['available'] ?? true),
            (string) ($row['dedup_key'] ?? ''),
        );
    }
}
