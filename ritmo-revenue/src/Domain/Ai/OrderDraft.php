<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * An owner order the assistant drafted, as the fields of the order form: nothing is created until
 * a person opens it in the form and gives the order. Amounts are texts with two decimals, as the
 * form reads them. $derivation says how each computed floor came from the room's base price.
 */
final class OrderDraft
{
    /**
     * @param list<string>                                                          $rooms
     * @param list<string>                                                          $nights     YYYY-MM-DD; empty for every night from $from to $to
     * @param array<string, string>                                                 $floors     room code => amount
     * @param list<array{room: string, base: float, percent: float, floor: float}> $derivation
     */
    public function __construct(
        public readonly string $type,
        public readonly array $rooms,
        public readonly string $from,
        public readonly string $to,
        public readonly array $nights,
        public readonly array $floors,
        public readonly ?int $percentile,
        public readonly string $note,
        public readonly array $derivation = [],
    ) {
    }

    /**
     * The fields of the order form (OrderForm::toOrder and the form's draft).
     *
     * @return array{type: string, rooms: list<string>, from: string, to: string, nights: list<string>, floors: array<string, string>, percentile: string, releases: list<string>, note: string}
     */
    public function formFields(): array
    {
        return [
            'type' => $this->type,
            'rooms' => $this->rooms,
            'from' => $this->from,
            'to' => $this->to,
            'nights' => $this->nights,
            'floors' => $this->floors,
            'percentile' => $this->percentile === null ? '' : (string) $this->percentile,
            'releases' => [],
            'note' => $this->note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->formFields() + ['derivation' => $this->derivation];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $strings = static fn (mixed $v): array => array_values(array_filter(is_array($v) ? $v : [], 'is_string'));
        $floors = [];
        foreach (is_array($data['floors'] ?? null) ? $data['floors'] : [] as $room => $amount) {
            if (is_string($room) && is_string($amount)) {
                $floors[$room] = $amount;
            }
        }
        $derivation = [];
        foreach (is_array($data['derivation'] ?? null) ? $data['derivation'] : [] as $row) {
            if (is_array($row) && is_string($row['room'] ?? null)) {
                $derivation[] = ['room' => $row['room'], 'base' => (float) ($row['base'] ?? 0), 'percent' => (float) ($row['percent'] ?? 0), 'floor' => (float) ($row['floor'] ?? 0)];
            }
        }
        $percentile = $data['percentile'] ?? '';

        return new self(
            (string) ($data['type'] ?? 'follow_up'),
            $strings($data['rooms'] ?? []),
            (string) ($data['from'] ?? ''),
            (string) ($data['to'] ?? ''),
            $strings($data['nights'] ?? []),
            $floors,
            is_numeric($percentile) ? (int) $percentile : null,
            (string) ($data['note'] ?? ''),
            $derivation,
        );
    }
}
