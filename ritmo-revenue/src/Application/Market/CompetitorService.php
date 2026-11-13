<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;

/**
 * Adds, changes and archives the competitors of a property, and rebuilds the market series
 * after each change (a tag or a factor changes the series). Each change is an event of the
 * change log with who and when. A competitor with prices is never deleted.
 */
final class CompetitorService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PropertyRepository $properties,
        private readonly CompetitorRepository $competitors,
        private readonly EditorEventRepository $events,
        private readonly MarketSeriesService $series,
    ) {
    }

    /**
     * Creates a competitor ($id null) or changes one.
     *
     * @param array<string, mixed> $input see CompetitorInput::read()
     *
     * @return int the competitor's id
     *
     * @throws MarketRefused when a field does not read, the code is taken or the competitor is unknown
     */
    public function save(string $propertyId, ?int $id, array $input, int $userId): int
    {
        if ($this->properties->find($propertyId) === null) {
            throw new MarketRefused(MarketRefused::PROPERTY_UNKNOWN);
        }
        $fields = CompetitorInput::read($input);
        $before = $id === null ? null : ($this->competitors->find($propertyId, $id) ?? throw new MarketRefused(MarketRefused::COMPETITOR_UNKNOWN));
        if ($this->competitors->codeTaken($propertyId, $fields['code'], $id ?? 0)) {
            throw new MarketRefused(MarketRefused::CODE_TAKEN, 'code');
        }
        $saved = $this->db->transaction(function () use ($propertyId, $id, $fields, $before, $userId): int {
            if ($id !== null) {
                $this->competitors->update($propertyId, $id, $fields);
            }
            $saved = $id ?? $this->competitors->add($propertyId, $fields);
            $this->events->record($propertyId, EditorEventRepository::COMPETITOR, $before['code'] ?? '', $fields['code'], $id === null ? 'Competitor added' : 'Competitor changed', 'user:' . $userId, $this->clock->now(), ['competitor' => $saved]);

            return $saved;
        });
        $this->series->rebuild($propertyId);

        return $saved;
    }

    /**
     * Archives a competitor: it stops counting; its prices stay.
     *
     * @throws MarketRefused when the competitor is unknown
     */
    public function archive(string $propertyId, int $id, int $userId): void
    {
        $competitor = $this->competitors->find($propertyId, $id) ?? throw new MarketRefused(MarketRefused::COMPETITOR_UNKNOWN);
        $this->db->transaction(function () use ($propertyId, $id, $competitor, $userId): void {
            $this->competitors->archive($propertyId, $id);
            $this->events->record($propertyId, EditorEventRepository::COMPETITOR, $competitor['code'], '', 'Competitor archived', 'user:' . $userId, $this->clock->now(), ['competitor' => $id]);
        });
        $this->series->rebuild($propertyId);
    }

    /**
     * Creates, in one go, the competitors a price file named but the property does not have
     * (active, no tag, no factor), so the file can be previewed again (MK-D7).
     *
     * @param list<string> $names as typed in the file
     *
     * @return int how many were created
     *
     * @throws MarketRefused when a name does not read
     */
    public function createMissing(string $propertyId, array $names, int $userId): int
    {
        $created = 0;
        foreach (array_slice(array_values(array_unique($names)), 0, 50) as $name) {
            $code = CompetitorInput::slug($name);
            if ($this->competitors->codeTaken($propertyId, $code)) {
                continue;
            }
            $this->save($propertyId, null, ['name' => $name, 'code' => $code], $userId);
            ++$created;
        }

        return $created;
    }
}
