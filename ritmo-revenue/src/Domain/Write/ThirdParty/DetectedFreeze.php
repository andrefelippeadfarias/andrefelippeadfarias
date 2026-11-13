<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\ThirdParty;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Support\Days;

/**
 * The freeze of the nights of one room changed by someone else: exactly those nights, whatever
 * their date, in both systems (no price, stay, restriction or deletion), until a person releases
 * it, the night passes or the configured days end. While active it becomes a manual owner order,
 * so the engine stays away and the Validator refuses any item or deletion there.
 *
 * Reference: ordens.registrar_manual freezes the nights from today to today + 7 only; a change
 * further out would not be frozen at all (divergence C5 of docs/VERIFY.md).
 */
final class DetectedFreeze
{
    /** @var list<DateTimeImmutable> */
    public readonly array $nights;

    public readonly DateTimeImmutable $detectedAt;

    public readonly ?DateTimeImmutable $frozenUntil;

    public readonly ?DateTimeImmutable $releasedAt;

    /**
     * @param list<DateTimeImmutable> $nights
     * @param list<string>            $fields      the fields that changed
     * @param DateTimeImmutable|null  $frozenUntil null: until released or the night passes
     *
     * @throws InvalidArgumentException on an invalid room or no night
     */
    public function __construct(
        public readonly string $roomCode,
        array $nights,
        DateTimeImmutable $detectedAt,
        ?DateTimeImmutable $frozenUntil,
        public readonly array $fields = [],
        public readonly ?int $id = null,
        ?DateTimeImmutable $releasedAt = null,
    ) {
        if (!Identifier::isCode($roomCode) || $nights === []) {
            throw new InvalidArgumentException('A freeze is of at least one night of a room.');
        }
        $days = array_map(static fn (DateTimeImmutable $n): DateTimeImmutable => Days::normalize($n), $nights);
        usort($days, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $utc = new DateTimeZone('UTC');
        $this->nights = array_values(array_unique($days, SORT_REGULAR));
        $this->detectedAt = $detectedAt->setTimezone($utc);
        $this->frozenUntil = $frozenUntil?->setTimezone($utc);
        $this->releasedAt = $releasedAt?->setTimezone($utc);
    }

    /**
     * One freeze per room for the changes found, with the configured length.
     *
     * @param list<ThirdPartyChange> $changes
     *
     * @return list<self>
     */
    public static function fromChanges(array $changes, DateTimeImmutable $detectedAt, ThirdPartyConfig $config): array
    {
        $byRoom = [];
        foreach ($changes as $change) {
            $byRoom[$change->roomCode]['nights'][Days::key($change->night)] = $change->night;
            foreach (array_keys($change->fields) as $field) {
                $byRoom[$change->roomCode]['fields'][$field] = true;
            }
        }
        ksort($byRoom);
        $until = $config->untilNightPasses ? null : $detectedAt->modify(sprintf('+%d days', $config->freezeDays));
        $freezes = [];
        foreach ($byRoom as $room => $found) {
            $fields = array_keys($found['fields'] ?? []);
            sort($fields);
            $freezes[] = new self((string) $room, array_values($found['nights']), $detectedAt, $until, $fields);
        }

        return $freezes;
    }

    /**
     * The same freeze with the id it was stored under.
     */
    public function withId(int $id): self
    {
        return new self($this->roomCode, $this->nights, $this->detectedAt, $this->frozenUntil, $this->fields, $id, $this->releasedAt);
    }

    /**
     * The nights still frozen: not released, not past its end, from today on.
     *
     * @return list<DateTimeImmutable>
     */
    public function activeNights(DateTimeImmutable $now, DateTimeImmutable $today): array
    {
        if ($this->releasedAt !== null || ($this->frozenUntil !== null && $now >= $this->frozenUntil)) {
            return [];
        }
        $day = Days::normalize($today);

        return array_values(array_filter($this->nights, static fn (DateTimeImmutable $n): bool => $n >= $day));
    }

    /**
     * The manual orders that keep the engine and the Validator away from the active nights, one
     * per run of consecutive nights (a manual order covers every night from its first to its last).
     *
     * @return list<OwnerOrder>
     */
    public function toOrders(DateTimeImmutable $now, DateTimeImmutable $today): array
    {
        $runs = [];
        foreach ($this->activeNights($now, $today) as $night) {
            $last = $runs === [] ? null : $runs[count($runs) - 1][count($runs[count($runs) - 1]) - 1];
            if ($last !== null && Days::diff($last, $night) === 1) {
                $runs[count($runs) - 1][] = $night;
            } else {
                $runs[] = [$night];
            }
        }

        return array_map(fn (array $run): OwnerOrder => new OwnerOrder(
            'detected-' . substr(hash('sha256', $this->roomCode . '|' . Days::key($run[0]) . '|' . $this->detectedAt->format('U')), 0, 20),
            OrderType::Manual,
            [$this->roomCode],
            $run[0],
            $run[count($run) - 1],
            givenOn: Days::normalize($today),
            createdAt: $this->detectedAt,
            note: 'Changed by someone else: ' . implode(', ', $this->fields),
        ), $runs);
    }
}
