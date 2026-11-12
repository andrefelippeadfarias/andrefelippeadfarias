<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The occupancy that makes a night an orphan, as read when the change was planned, so that a
 * validator can check it before writing.
 *
 * From the PMS inventory: units, the units still for sale on the night and on its neighbour, and
 * the instant of the read. From the pricing calendar: units sold and total of both nights. A
 * closed day whose previous night has passed carries that night's date in previousPast instead
 * of a sold-out neighbour.
 *
 * Reference: calendario_12m.Ocupacao.prova and prova_sexta_passada.
 */
final class OrphanProof
{
    public const SOURCE_PMS = 'pms';
    public const SOURCE_PRICING = 'pricing';
    public const NEXT = 'next';
    public const PREVIOUS = 'previous';

    public readonly ?DateTimeImmutable $previousPast;

    /**
     * @throws InvalidArgumentException on an unknown source or neighbour
     */
    public function __construct(
        public readonly string $source,
        public readonly string $neighbour,
        public readonly ?int $units = null,
        public readonly ?int $nightAvailable = null,
        public readonly ?int $neighbourAvailable = null,
        public readonly ?int $nightSold = null,
        public readonly ?int $nightTotal = null,
        public readonly ?int $neighbourSold = null,
        public readonly ?int $neighbourTotal = null,
        public readonly ?DateTimeImmutable $readAt = null,
        ?DateTimeImmutable $previousPast = null,
    ) {
        if (!in_array($source, [self::SOURCE_PMS, self::SOURCE_PRICING], true)) {
            throw new InvalidArgumentException(sprintf('Unknown proof source "%s".', $source));
        }
        if (!in_array($neighbour, [self::NEXT, self::PREVIOUS], true)) {
            throw new InvalidArgumentException(sprintf('Unknown proof neighbour "%s".', $neighbour));
        }
        $this->previousPast = $previousPast === null ? null : Days::normalize($previousPast);
    }

    /**
     * A copy that marks the previous night as past (it no longer sells and counts as sold out).
     */
    public function withPreviousPast(DateTimeImmutable $night, bool $dropPricingNeighbour): self
    {
        return new self(
            $this->source,
            $this->neighbour,
            $this->units,
            $this->nightAvailable,
            $this->neighbourAvailable,
            $this->nightSold,
            $this->nightTotal,
            $dropPricingNeighbour ? null : $this->neighbourSold,
            $dropPricingNeighbour ? null : $this->neighbourTotal,
            $this->readAt,
            $night,
        );
    }

    /**
     * The proof as snake_case fields, without the ones it does not have.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'source' => $this->source,
            'neighbour' => $this->neighbour,
            'units' => $this->units,
            'night_available' => $this->nightAvailable,
            'neighbour_available' => $this->neighbourAvailable,
            'night_sold' => $this->nightSold,
            'night_total' => $this->nightTotal,
            'neighbour_sold' => $this->neighbourSold,
            'neighbour_total' => $this->neighbourTotal,
            'read_at' => $this->readAt?->format(DATE_ATOM),
            'previous_past' => $this->previousPast === null ? null : Days::key($this->previousPast),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
