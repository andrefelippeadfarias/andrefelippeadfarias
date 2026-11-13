<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan\Validation;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\Validation\AuthorityCheck;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * Findings F2 and X1 of the M2 review: a deletion on a night frozen by a manual change, and any
 * entry on a night whose price an owner order fixed, are refused (docs/VERIFY.md, section 7).
 */
final class OrderGuardsTest extends TestCase
{
    public function testADeletionOnAFrozenNightIsRefused(): void
    {
        $context = PlanSample::context(orders: PlanSample::orders([['id' => 'manual-1', 'type' => 'manual', 'rooms' => ['Deluxe'], 'from' => '2027-03-09', 'to' => '2027-03-11']]));

        $result = PlanSample::validate([], $context, null, [new PlanDeletion('Deluxe', Days::date('2027-03-10'), 'release_ceiling')]);

        self::assertSame([PlanIssue::NIGHT_FROZEN], $result->errorCodes());
        self::assertSame([0], $result->rejected(IssueSubject::Deletion));
    }

    public function testADeletionOutsideTheFreezeStillPasses(): void
    {
        $context = PlanSample::context(orders: PlanSample::orders([['id' => 'manual-1', 'type' => 'manual', 'rooms' => ['Deluxe'], 'from' => '2027-03-09', 'to' => '2027-03-11']]));

        $result = PlanSample::validate([], $context, null, [
            new PlanDeletion('Deluxe', Days::date('2027-03-12'), 'release_ceiling'),
            new PlanDeletion('Standard', Days::date('2027-03-10'), 'release_ceiling'),
        ]);

        self::assertSame([], $result->errorCodes());
    }

    public function testAnItemOnAFixedPriceNightIsRefusedUnlessItCitesThatOrder(): void
    {
        $context = PlanSample::context(orders: PlanSample::orders([['id' => 'fixed-1', 'type' => 'fixed_price', 'rooms' => ['Deluxe'], 'from' => '2027-03-10', 'to' => '2027-03-10']]));

        self::assertSame([AuthorityCheck::NIGHT_FIXED_BY_ORDER], PlanSample::validate([PlanSample::item()], $context)->errorCodes());
        self::assertSame([AuthorityCheck::NIGHT_FIXED_BY_ORDER], PlanSample::validate([PlanSample::item(['minPrice' => null, 'minStay' => 3])], $context)->errorCodes(), 'a stay-only item too');
        self::assertSame([], PlanSample::validate([PlanSample::item(['orderId' => 'fixed-1'])], $context)->errorCodes(), 'the order itself may be written');
        self::assertSame([], PlanSample::validate([PlanSample::item(['date' => '2027-03-11'])], $context)->errorCodes(), 'another night');
    }

    public function testADeletionOnAFixedPriceNightIsRefused(): void
    {
        $context = PlanSample::context(orders: PlanSample::orders([['id' => 'fixed-1', 'type' => 'fixed_price', 'rooms' => ['*'], 'from' => '2027-03-10', 'to' => '2027-03-10']]));

        $result = PlanSample::validate([], $context, null, [new PlanDeletion('Standard', Days::date('2027-03-10'), 'release_ceiling')]);

        self::assertSame([AuthorityCheck::NIGHT_FIXED_BY_ORDER], $result->errorCodes());
    }

    public function testAListingChangeIsNotHeldByAFixedPriceOfToday(): void
    {
        $context = PlanSample::context(orders: PlanSample::orders([
            ['id' => 'fixed-1', 'type' => 'fixed_price', 'rooms' => ['Deluxe'], 'from' => PlanSample::TODAY, 'to' => PlanSample::TODAY],
            ['id' => 'listing-order', 'type' => 'rule', 'releases' => ['listing']],
        ]), weekly: ['Deluxe' => ['base' => 1000.0, 'min' => 600.0]]);

        $result = PlanSample::validate([], $context, null, [], [new ListingChange('Deluxe', ListingField::Base, 1020.0, 'base_review', '', 'listing-order')]);

        self::assertNotContains(AuthorityCheck::NIGHT_FIXED_BY_ORDER, $result->errorCodes());
    }
}
