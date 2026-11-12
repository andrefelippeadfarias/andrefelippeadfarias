<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;

/**
 * One row of the step ledger: a step that was written to the pricing system. Immutable.
 *
 * Reference: dados/passos.json rows (quarto, noite, de, para, direcao, status, vendidas, base,
 * total, gravado_em, chegou_ao_canal).
 */
final class StepRecord
{
    /** Source status of a step written by a sales ladder. */
    public const LADDER = 'ladder';

    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})?$/';

    public readonly DateTimeImmutable $night;

    public readonly DateTimeImmutable $writtenAt;

    public readonly ?DateTimeImmutable $onChannelAt;

    /**
     * @param bool                   $minutePrecision the write instant was stored without seconds: the step may
     *                                                have been written in any second of that minute
     * @param DateTimeImmutable|null $onChannelAt     instant of the push that carried the step to the channel
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly float $priceFrom,
        public readonly float $priceTo,
        public readonly StepDirection $direction,
        public readonly string $sourceStatus,
        public readonly ?int $sold,
        public readonly SoldBase $soldBase,
        public readonly ?int $soldTotal,
        DateTimeImmutable $writtenAt,
        public readonly bool $minutePrecision = false,
        ?DateTimeImmutable $onChannelAt = null,
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        $utc = new DateTimeZone('UTC');
        $this->night = Days::normalize($night);
        $this->writtenAt = $writtenAt->setTimezone($utc);
        $this->onChannelAt = $onChannelAt?->setTimezone($utc);
    }

    /**
     * A row of the ledger as stored: room, night, price_from, price_to, direction, source_status,
     * sold, sold_base (missing = room), sold_total, written_at, on_channel_at (null = not yet).
     * Instants without an offset are UTC.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException on a malformed row
     */
    public static function fromArray(array $row): self
    {
        $written = (string) ($row['written_at'] ?? '');

        return new self(
            (string) ($row['room'] ?? ''),
            Days::date((string) ($row['night'] ?? '')),
            (float) ($row['price_from'] ?? 0),
            (float) ($row['price_to'] ?? 0),
            StepDirection::from((string) ($row['direction'] ?? '')),
            (string) ($row['source_status'] ?? ''),
            isset($row['sold']) ? (int) $row['sold'] : null,
            SoldBase::from((string) ($row['sold_base'] ?? SoldBase::Room->value)),
            isset($row['sold_total']) ? (int) $row['sold_total'] : null,
            self::instant($written),
            strlen($written) < 19 || $written[16] !== ':',
            isset($row['on_channel_at']) ? self::instant((string) $row['on_channel_at']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'room' => $this->roomCode,
            'night' => Days::key($this->night),
            'price_from' => $this->priceFrom,
            'price_to' => $this->priceTo,
            'direction' => $this->direction->value,
            'source_status' => $this->sourceStatus,
            'sold' => $this->sold,
            'sold_base' => $this->soldBase->value,
            'sold_total' => $this->soldTotal,
            'written_at' => $this->writtenAt->format($this->minutePrecision ? 'Y-m-d\TH:iP' : 'Y-m-d\TH:i:sP'),
            'on_channel_at' => $this->onChannelAt?->format('Y-m-d\TH:iP'),
        ];
    }

    public function isLadderStep(): bool
    {
        return $this->sourceStatus === self::LADDER;
    }

    public function isFor(string $room, DateTimeImmutable $night): bool
    {
        return $this->roomCode === $room && $this->night == Days::normalize($night);
    }

    /**
     * The latest instant the step may have been written: a row stored to the minute may have
     * been written in any second of that minute.
     */
    public function writtenAtLatest(): DateTimeImmutable
    {
        return $this->minutePrecision ? $this->writtenAt->modify('+59 seconds') : $this->writtenAt;
    }

    /**
     * The same row, arrived on the channel with the push at $pushedAt (kept to the minute).
     */
    public function onChannel(DateTimeImmutable $pushedAt): self
    {
        $minute = $pushedAt->setTimezone(new DateTimeZone('UTC'));
        $minute = $minute->setTime((int) $minute->format('H'), (int) $minute->format('i'));

        return new self(
            $this->roomCode,
            $this->night,
            $this->priceFrom,
            $this->priceTo,
            $this->direction,
            $this->sourceStatus,
            $this->sold,
            $this->soldBase,
            $this->soldTotal,
            $this->writtenAt,
            $this->minutePrecision,
            $minute,
        );
    }

    /**
     * @throws InvalidArgumentException when the text is not an ISO 8601 instant
     */
    public static function instant(string $text): DateTimeImmutable
    {
        if (preg_match(self::INSTANT, $text) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not an ISO 8601 instant.', $text));
        }

        return (new DateTimeImmutable($text, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }
}
