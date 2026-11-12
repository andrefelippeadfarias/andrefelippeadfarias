<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Countable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * The changes proposed for one property in one run, with the day they were computed for: the
 * date rules to delete first, the items to write and the changes to the listings.
 *
 * A plan is only a proposal: nothing may be written from it before the Validator turns it into
 * a ValidatedPlan.
 */
final class Plan implements Countable
{
    public readonly DateTimeImmutable $today;

    public readonly DateTimeImmutable $createdAt;

    /** @var list<PlanItem> */
    public readonly array $items;

    /** @var list<PlanDeletion> */
    public readonly array $deletions;

    /** @var list<ListingChange> */
    public readonly array $listingChanges;

    /**
     * @param DateTimeImmutable   $today          the property's "today" the plan was computed for (date-only)
     * @param DateTimeImmutable   $createdAt      instant the plan was computed
     * @param list<PlanItem>      $items
     * @param list<PlanDeletion>  $deletions      date rules deleted before the items are written
     * @param list<ListingChange> $listingChanges
     *
     * @throws InvalidArgumentException on an invalid property id or an entry of the wrong class
     */
    public function __construct(
        public readonly string $propertyId,
        DateTimeImmutable $today,
        DateTimeImmutable $createdAt,
        array $items,
        array $deletions = [],
        array $listingChanges = [],
    ) {
        if (!Identifier::isCode($propertyId)) {
            throw new InvalidArgumentException(sprintf('Invalid property id "%s".', $propertyId));
        }
        self::assertAll($items, PlanItem::class);
        self::assertAll($deletions, PlanDeletion::class);
        self::assertAll($listingChanges, ListingChange::class);
        $this->today = Days::normalize($today);
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
        $this->items = array_values($items);
        $this->deletions = array_values($deletions);
        $this->listingChanges = array_values($listingChanges);
    }

    /**
     * The plan of the steps among the decisions of the pace engine, dated by the clock.
     *
     * @param list<PaceDecision>        $decisions decisions of rooms of the property; those that are not steps are left out
     * @param array<string, NightState> $before    what is in force, by PlanItem::keyOf() ("CODE|YYYY-MM-DD")
     */
    public static function fromDecisions(Property $property, Clock $clock, array $decisions, array $before = []): self
    {
        $items = [];
        foreach ($decisions as $decision) {
            if ($decision->isStep()) {
                $items[] = PlanItem::fromDecision($decision, $before[PlanItem::keyOf($decision->roomCode, $decision->night)] ?? null);
            }
        }

        return new self($property->id, $clock->today($property->timezone), $clock->now(), $items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * The same plan without the entries at the given positions (those a validation pointed at).
     *
     * @param list<int> $indexes         positions in $items
     * @param list<int> $deletions       positions in $deletions
     * @param list<int> $listingChanges  positions in $listingChanges
     */
    public function without(array $indexes, array $deletions = [], array $listingChanges = []): self
    {
        return new self(
            $this->propertyId,
            $this->today,
            $this->createdAt,
            array_values(array_diff_key($this->items, array_flip($indexes))),
            array_values(array_diff_key($this->deletions, array_flip($deletions))),
            array_values(array_diff_key($this->listingChanges, array_flip($listingChanges))),
        );
    }

    /**
     * @param array<mixed> $entries
     * @param class-string $class
     */
    private static function assertAll(array $entries, string $class): void
    {
        foreach ($entries as $entry) {
            if (!$entry instanceof $class) {
                throw new InvalidArgumentException(sprintf('A plan holds %s objects there only.', $class));
            }
        }
    }
}
