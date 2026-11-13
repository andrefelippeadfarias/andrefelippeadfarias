<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Approval;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalKind;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\Approval\ApprovalStatus;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Application\Verify\Capabilities;
use Ritmo\Tests\Unit\Application\Verify\VerifyDatabase;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * Plans waiting for a person: proposed, approved in full or by whole groups, rejected, expired,
 * superseded; listing values approved one by one (docs/VERIFY.md, section 6).
 */
final class ApprovalServiceTest extends TestCase
{
    private const APPROVER = 3;
    private const VIEWER = 9;
    private const SAFETY = 4;

    private PlanApprovalRepository $repository;

    private MovableClock $clock;

    private Capabilities $capabilities;

    private ApprovalService $service;

    protected function setUp(): void
    {
        $this->repository = new PlanApprovalRepository(VerifyDatabase::create());
        $this->clock = new MovableClock(PlanSample::NOW);
        $this->capabilities = new Capabilities([self::APPROVER => [CapabilityCheck::APPROVE], self::SAFETY => [CapabilityCheck::APPROVE, CapabilityCheck::MANAGE_SAFETY]]);
        $this->service = new ApprovalService($this->repository, $this->capabilities, $this->clock);
    }

    public function testAPlanIsProposedWithListingValuesApart(): void
    {
        $ids = $this->service->propose(self::plan(), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'), 5);

        self::assertCount(2, $ids);
        $nights = $this->repository->find($ids[0]);
        $listing = $this->repository->find($ids[1]);
        self::assertSame(['plan', 'listing'], [$nights['kind'], $listing['kind']]);
        self::assertSame(['item|Deluxe|2027-03-10', 'item|Deluxe|2027-03-11', 'deletion|Deluxe|2027-03-11'], array_column($nights['entries'], 'key'));
        self::assertSame('2027-03-03T17:00:00+00:00', $nights['expires_at']->format(DATE_ATOM), 'six hours after the calendar, before midnight');
        self::assertSame(['floor_pct' => -10], $nights['entries'][0]['fields']);
    }

    public function testApprovingEverythingGivesAGrant(): void
    {
        [$id] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $grant = $this->service->approve($id, self::APPROVER);

        self::assertSame(3, count($grant->approvedEntries));
        self::assertSame([self::APPROVER, CapabilityCheck::APPROVE, ApprovalKind::Plan], [$grant->approvedBy, $grant->capability, $grant->kind]);
        self::assertSame('approved', $this->repository->find($id)['status']);
        self::assertEquals($grant, $this->service->grant($id));
    }

    public function testAPartialApprovalTakesWholeGroups(): void
    {
        [$id] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $grant = $this->service->approve($id, self::APPROVER, ['deletion|Deluxe|2027-03-11'], 'only the release');

        self::assertSame(['item|Deluxe|2027-03-11', 'deletion|Deluxe|2027-03-11'], $grant->approvedKeys());
        self::assertSame(['item|Deluxe|2027-03-10'], $this->repository->find($id)['rejected_keys']);
    }

    public function testListingValuesNeedTheirNamesTheSafetyCapabilityAndTheTypedAmount(): void
    {
        [, $listing] = $this->service->propose(self::plan(), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $typed = ['listing|Deluxe|base' => '1020'];

        self::assertRefused(ApprovalRefused::LISTING_NEEDS_EXPLICIT_APPROVAL, fn () => $this->service->approve($listing, self::SAFETY));
        self::assertRefused(ApprovalRefused::LISTING_NEEDS_SAFETY, fn () => $this->service->approve($listing, self::APPROVER, ['listing|Deluxe|base'], '', $typed));
        self::assertRefused(ApprovalRefused::LISTING_NOT_CONFIRMED, fn () => $this->service->approve($listing, self::SAFETY, ['listing|Deluxe|base']));
        self::assertRefused(ApprovalRefused::LISTING_NOT_CONFIRMED, fn () => $this->service->approve($listing, self::SAFETY, ['listing|Deluxe|base'], '', ['listing|Deluxe|base' => '1200']));
        self::assertSame('pending', $this->repository->find($listing)['status'], 'nothing was decided');

        $grant = $this->service->approve($listing, self::SAFETY, ['listing|Deluxe|base'], '', ['listing|Deluxe|base' => '1020,00']);
        self::assertSame(['listing|Deluxe|base'], $grant->approvedKeys());
        self::assertSame(['Deluxe|base'], $grant->listingChanges());
        self::assertEquals($grant, $this->service->grant($listing));
    }

    public function testTheWriteOfAListingChangeNeedsTheApproverToStillHoldTheSafetyCapability(): void
    {
        [, $listing] = $this->service->propose(self::plan(), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $this->service->approve($listing, self::SAFETY, ['listing|Deluxe|base'], '', ['listing|Deluxe|base' => '1020']);
        $lost = new ApprovalService($this->repository, new Capabilities([self::SAFETY => [CapabilityCheck::APPROVE]]), $this->clock);

        self::assertRefused(ApprovalRefused::LISTING_NEEDS_SAFETY, fn () => $lost->grant($listing));
    }

    public function testOnlyAUserWithTheCapabilityDecides(): void
    {
        [$id] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));

        self::assertRefused(ApprovalRefused::NO_CAPABILITY, fn () => $this->service->approve($id, self::VIEWER));
        self::assertRefused(ApprovalRefused::NO_CAPABILITY, fn () => $this->service->reject($id, self::VIEWER));
        self::assertRefused(ApprovalRefused::UNKNOWN_ENTRY, fn () => $this->service->approve($id, self::APPROVER, ['item|Standard|2027-03-10']));
    }

    public function testARequestExpiresWithItsCalendarOrTheDay(): void
    {
        [$id] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $this->clock->advance(5 * 3600);

        self::assertRefused(ApprovalRefused::EXPIRED, fn () => $this->service->approve($id, self::APPROVER));
        self::assertSame(['expired', ApprovalService::DAY_OR_CALENDAR], [$this->repository->find($id)['status'], $this->repository->find($id)['expiry_reason']]);
    }

    public function testAnApprovalLapsesWhenTheWriteComesTooLateOrTheApproverLostTheCapability(): void
    {
        [$first] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $this->service->approve($first, self::APPROVER);
        $this->capabilities->revoke(self::APPROVER);
        self::assertRefused(ApprovalRefused::NO_CAPABILITY, fn () => $this->service->grant($first));

        $this->clock->advance(6 * 3600);
        self::assertRefused(ApprovalRefused::EXPIRED, fn () => $this->service->grant($first));
        self::assertSame(ApprovalStatus::Lapsed->value, $this->repository->find($first)['status']);
    }

    public function testANewerPlanSupersedesTheOlderAndARejectionWritesNothing(): void
    {
        [$old] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        [$new] = $this->service->propose(self::plan(false), 42, new DateTimeImmutable('2027-03-03T11:30:00Z'));

        self::assertSame('superseded', $this->repository->find($old)['status']);
        self::assertRefused(ApprovalRefused::NOT_PENDING, fn () => $this->service->approve($old, self::APPROVER));
        $this->service->reject($new, self::APPROVER, 'not today');
        self::assertSame('rejected', $this->repository->find($new)['status']);
        self::assertRefused(ApprovalRefused::NOT_APPROVED, fn () => $this->service->grant($new));
    }

    public function testExpireAllEndsThePendingRequests(): void
    {
        $this->service->propose(self::plan(), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));

        self::assertSame(2, $this->service->expireAll('inn', 'mode_changed'));
        self::assertSame([], $this->repository->pending('inn'));
    }

    public function testExpireAllAlsoLapsesTheApprovalsNotWrittenYet(): void
    {
        [$id] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $this->service->approve($id, self::APPROVER);

        self::assertSame(1, $this->service->expireAll('inn', 'mode_changed'));
        self::assertSame(['lapsed', 'mode_changed'], [$this->repository->find($id)['status'], $this->repository->find($id)['expiry_reason']]);
        self::assertSame([], $this->repository->approved('inn'));
        self::assertRefused(ApprovalRefused::NOT_APPROVED, fn () => $this->service->grant($id));
    }

    public function testANewApprovalOrANewProposalEndsTheOlderApprovalNotWrittenYet(): void
    {
        [$first] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $this->service->approve($first, self::APPROVER);
        [$second] = $this->service->propose(self::plan(false), 42, new DateTimeImmutable('2027-03-03T11:30:00Z'));

        self::assertSame('lapsed', $this->repository->find($first)['status'], 'a newer plan replaces the approval not written yet');
        $this->service->approve($second, self::APPROVER);
        self::assertSame([$second], array_column($this->repository->approved('inn'), 'id'), 'at most one approval of a kind waits for its write');
    }

    public function testAWrittenApprovalIsClosed(): void
    {
        [$id] = $this->service->propose(self::plan(false), 41, new DateTimeImmutable('2027-03-03T11:00:00Z'));
        $this->service->approve($id, self::APPROVER);

        self::assertTrue($this->service->markWritten($id, 77));
        self::assertSame(['written', 77], [$this->repository->find($id)['status'], $this->repository->find($id)['write_run_id']]);
    }

    public static function plan(bool $withListing = true): ValidatedPlan
    {
        $context = PlanSample::context(
            orders: PlanSample::orders([['id' => 'listing-order', 'type' => 'rule', 'releases' => ['listing']]]),
            weekly: ['Deluxe' => ['base' => 1000.0, 'min' => 600.0]],
        );
        $result = PlanSample::validate(
            [PlanSample::item(), PlanSample::item(['date' => '2027-03-11', 'minPrice' => 950.0])],
            $context,
            null,
            [new PlanDeletion('Deluxe', Days::date('2027-03-11'), 'release_ceiling')],
            $withListing ? [new ListingChange('Deluxe', ListingField::Base, 1020.0, 'base_review', '', 'listing-order')] : [],
        );
        self::assertSame([], $result->errorCodes());

        return $result->plan ?? throw new LogicException('not validated');
    }

    private static function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('expected a refusal: ' . $reason);
        } catch (ApprovalRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
