<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

final class PlanTest extends TestCase
{
    public function testHoldsItsItemsTheDayAndTheInstant(): void
    {
        $items = [5 => new PlanItem('Deluxe', Days::date('2027-03-10'), 900.0), 9 => new PlanItem('Standard', Days::date('2027-03-10'), 400.0)];
        $plan = new Plan('inn', new DateTimeImmutable('2027-03-03T09:00:00+00:00'), new DateTimeImmutable('2027-03-03T09:00:00-03:00'), $items);

        self::assertSame('inn', $plan->propertyId);
        self::assertSame('2027-03-03 00:00:00', $plan->today->format('Y-m-d H:i:s'));
        self::assertSame('2027-03-03T12:00:00+00:00', $plan->createdAt->format('c'));
        self::assertCount(2, $plan);
        self::assertSame([0, 1], array_keys($plan->items), 'items are a list');
    }

    public function testRejectsAnInvalidPropertyOrForeignItems(): void
    {
        $today = Days::date('2027-03-03');
        foreach ([['not a code', []], ['inn', ['Deluxe']]] as [$propertyId, $items]) {
            try {
                new Plan($propertyId, $today, $today, $items);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWithoutDropsItemsByPosition(): void
    {
        $today = Days::date('2027-03-03');
        $items = [new PlanItem('Deluxe', Days::date('2027-03-10'), 900.0), new PlanItem('Deluxe', Days::date('2027-03-11'), 901.0), new PlanItem('Deluxe', Days::date('2027-03-12'), 902.0)];
        $plan = new Plan('inn', $today, $today, $items);

        $smaller = $plan->without([0, 2]);

        self::assertSame([$items[1]], $smaller->items);
        self::assertEquals([$plan->propertyId, $plan->today, $plan->createdAt], [$smaller->propertyId, $smaller->today, $smaller->createdAt]);
        self::assertCount(3, $plan, 'the original is untouched');
        self::assertCount(3, $plan->without([]));
    }

    public function testFromDecisionsKeepsTheStepsAndDatesThePlanByTheClock(): void
    {
        $clock = new FixedClock('2027-03-03T12:00:00+00:00');
        $engine = new PaceEngine(EngineSample::rules(), $clock);
        $decide = static fn (string $night, int $sold): PaceDecision => $engine->decide(
            new NightContext(EngineSample::property(), EngineSample::deluxe(), Days::date($night), 1000.0, $sold, 4, EngineSample::anchor($night))
        );
        $before = ['Deluxe|2027-03-10' => new NightState(1000.0, 600.0, 3000.0)];

        $plan = Plan::fromDecisions(EngineSample::property(), $clock, [$decide('2027-03-10', 0), $decide('2027-03-11', 4), $decide('2027-03-09', 0)], $before);

        self::assertSame('inn', $plan->propertyId);
        self::assertSame('2027-03-03', Days::key($plan->today));
        self::assertEquals($clock->now(), $plan->createdAt);
        self::assertSame(['Deluxe|2027-03-10', 'Deluxe|2027-03-09'], array_map(static fn (PlanItem $item): string => $item->key(), $plan->items), 'the sold-out night is not a step');
        self::assertSame($before['Deluxe|2027-03-10'], $plan->items[0]->before);
        self::assertSame(1000.0, $plan->items[1]->before?->price);
    }

    public function testHoldsItsDeletionsAndListingChanges(): void
    {
        $now = new DateTimeImmutable('2027-03-03T12:00:00Z');
        $deletion = new PlanDeletion('Deluxe', new DateTimeImmutable('2027-03-10T15:00:00Z'), 'CEILING_RELEASE_DELETE', 'frees the ceiling');
        $change = new ListingChange('Deluxe', ListingField::Min, 640.0, 'OWNER_ORDER', '', 'order-1');
        $plan = new Plan('inn', Days::date('2027-03-03'), $now, [], [$deletion, $deletion], [$change]);

        self::assertSame('Deluxe|2027-03-10', $plan->deletions[0]->key());
        self::assertSame(['room' => 'Deluxe', 'date' => '2027-03-10', 'reason_code' => 'CEILING_RELEASE_DELETE', 'reason_text' => 'frees the ceiling'], $deletion->toArray());
        self::assertSame('min', $plan->listingChanges[0]->toArray()['field']);
        self::assertSame([1, 0], [count($plan->without([], [0], [0])->deletions), count($plan->without([], [0], [0])->listingChanges)]);
    }

    public function testRefusesEntriesWithoutShape(): void
    {
        $night = Days::date('2027-03-10');
        $now = new DateTimeImmutable('2027-03-03T12:00:00Z');
        $builders = [
            'deletion of a bad room code' => static fn () => new PlanDeletion('not a code', $night),
            'listing amount of zero' => static fn () => new ListingChange('Deluxe', ListingField::Base, 0.0),
            'listing with an empty order id' => static fn () => new ListingChange('Deluxe', ListingField::Base, 900.0, orderId: ''),
            'an item among the deletions' => static fn () => new Plan('inn', $night, $now, [], [new PlanItem('Deluxe', $night, 900.0)]),
        ];
        foreach ($builders as $name => $build) {
            try {
                $build();
                self::fail("Expected an InvalidArgumentException for: $name.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
