<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Stay\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Stay\Write\PmsChangeCheck;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Stay\Write\PmsCheckContext;
use Ritmo\Domain\Stay\Write\RevertLedger;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;

/**
 * Every code of the PMS change check with a change that passes and one that fails (spec 06, 3.5).
 */
final class PmsChangeCheckTest extends TestCase
{
    private const TODAY = '2026-12-01';

    public function testAPlainRuleChangePasses(): void
    {
        $entry = self::entry();
        self::assertSame([$entry], (new PmsChangeCheck())->check([$entry], self::context([$entry]))->accepted);
    }

    /**
     * @return iterable<string, array{0: string, 1: PmsChangeEntry, 2: array<string, mixed>}>
     */
    public static function refusals(): iterable
    {
        yield 'unknown room' => [PmsChangeCheck::ROOM_UNKNOWN, self::entry(room: 'XX'), []];
        yield 'availability without an order' => [PmsChangeCheck::FIELD_FORBIDDEN, self::entry(target: [ChangeField::PMS_AVAILABLE => 0]), []];
        yield 'availability with an order that does not allow it' => [PmsChangeCheck::FIELD_FORBIDDEN, self::entry(target: [ChangeField::PMS_AVAILABLE => 0], order: 'o-9'), []];
        yield 'stay above the limit' => [PmsChangeCheck::STAY_RANGE, self::entry(target: [ChangeField::PMS_MIN_STAY => 15]), []];
        yield 'minimum above the maximum read' => [PmsChangeCheck::STAY_RANGE, self::entry(target: [ChangeField::PMS_MIN_STAY => 5]), ['maxStay' => 3]];
        yield 'maximum above 30' => [PmsChangeCheck::STAY_RANGE, self::entry(target: [ChangeField::PMS_MAX_STAY => 31]), []];
        yield 'clearing a blackout' => [PmsChangeCheck::BLACKOUT, self::entry(target: [ChangeField::PMS_RESTRICTION => 'none'], before: [ChangeField::PMS_RESTRICTION => 'blackout']), []];
        yield 'yesterday' => [PmsChangeCheck::PAST_NIGHT, self::entry(night: '2026-11-30'), []];
        yield 'frozen night' => [PmsChangeCheck::FROZEN, self::entry(), ['frozen' => true]];
        yield 'orphan without proof' => [PmsChangeCheck::ORPHAN_PROOF, self::entry(target: [ChangeField::PMS_MIN_STAY => 1], reason: 'orphan_night'), []];
        yield 'orphan proof too old' => [PmsChangeCheck::ORPHAN_PROOF, self::entry(target: [ChangeField::PMS_MIN_STAY => 1], reason: 'orphan_night', proof: self::proof('2026-11-28T10:00:00Z')), []];
        yield 'orphan proof that no longer holds' => [PmsChangeCheck::ORPHAN_PROOF, self::entry(target: [ChangeField::PMS_MIN_STAY => 1], reason: 'orphan_night', proof: self::proof()), ['nextAvailable' => 1]];
        yield 'not what the rules ask' => [PmsChangeCheck::RULE_MISMATCH, self::entry(), ['notTarget' => true]];
        yield 'pushed field without a pair' => [PmsChangeCheck::UNPAIRED, self::entry(alsoPricing: true), []];
        yield 'pushed field whose pair was refused' => [PmsChangeCheck::UNPAIRED, self::entry(alsoPricing: true, paired: 'STD|2026-12-03'), []];
        yield 'put back by the push twice' => [PmsChangeCheck::REVERTED_BY_PUSH, self::entry(), ['reverts' => 2]];
    }

    /**
     * @dataProvider refusals
     *
     * @param array<string, mixed> $options
     */
    public function testEachRefusal(string $code, PmsChangeEntry $entry, array $options): void
    {
        $result = (new PmsChangeCheck())->check([$entry], self::context([$entry], $options));
        self::assertSame([], $result->accepted);
        self::assertSame($code, $result->refused[0]['code']);
    }

    public function testThePassingCounterparts(): void
    {
        $check = new PmsChangeCheck();
        $passing = [
            self::entry(target: [ChangeField::PMS_AVAILABLE => 0], order: 'o-1'),
            self::entry(target: [ChangeField::PMS_MIN_STAY => 1], reason: 'orphan_night', proof: self::proof()),
            self::entry(alsoPricing: true, paired: 'STD|2026-12-03'),
            self::entry(target: [ChangeField::PMS_MAX_STAY => 30]),
        ];
        foreach ($passing as $i => $entry) {
            $context = self::context([$entry], ['accepted' => ['STD|2026-12-03' => true], 'reverts' => 1]);
            self::assertSame([$entry], $check->check([$entry], $context)->accepted, "case $i");
        }
        $held = self::entry(alsoPricing: true)->with(true, null);
        self::assertSame([$held], $check->check([$held], self::context([$held]))->accepted, 'held by the pricing calendar');
    }

    public function testAnUndoSkipsTheRuleAndProofChecksButNoOther(): void
    {
        $undo = new PmsChangeEntry('STD', Days::date('2026-12-03'), new NightFields([ChangeField::PMS_MIN_STAY => 1, ChangeField::PMS_AVAILABLE => 2]), NightFields::none(), 'undo', undoOf: [41]);
        self::assertCount(1, (new PmsChangeCheck())->check([$undo], self::context([], ['notTarget' => true]))->accepted);
        self::assertSame(PmsChangeCheck::FROZEN, (new PmsChangeCheck())->check([$undo], self::context([], ['frozen' => true]))->refused[0]['code']);
    }

    public function testOneChangePerRoomNightAndField(): void
    {
        $a = self::entry();
        $b = self::entry(target: [ChangeField::PMS_MIN_STAY => 3]);
        $result = (new PmsChangeCheck())->check([$a, $b], self::context([$a, $b]));
        self::assertSame([$a], $result->accepted);
        self::assertSame(PmsChangeCheck::DUPLICATE, $result->refused[0]['code']);
    }

    public function testTheCapRefusesEveryChangeAtItsBoundary(): void
    {
        $entries = [self::entry(night: '2026-12-03'), self::entry(night: '2026-12-04'), self::entry(night: '2026-12-05')];
        $atCap = (new PmsChangeCheck())->check($entries, self::context($entries, ['max' => 3]));
        self::assertFalse($atCap->capped);
        self::assertCount(3, $atCap->accepted);
        $over = (new PmsChangeCheck())->check($entries, self::context($entries, ['max' => 2]));
        self::assertTrue($over->capped);
        self::assertSame([], $over->accepted);
        self::assertCount(3, $over->refusedWith(PmsChangeCheck::TOO_MANY));
    }

    /**
     * @param array<string, int|string> $target
     * @param array<string, int|string> $before
     */
    private static function entry(
        string $room = 'STD',
        string $night = '2026-12-03',
        array $target = [ChangeField::PMS_MIN_STAY => 2],
        array $before = [ChangeField::PMS_MIN_STAY => 1],
        string $reason = 'arrival_day',
        bool $alsoPricing = false,
        ?OrphanProof $proof = null,
        ?string $paired = null,
        ?string $order = null,
    ): PmsChangeEntry {
        return new PmsChangeEntry($room, Days::date($night), new NightFields($target), new NightFields($before), $reason, $alsoPricing, $proof, false, $paired, [], $order);
    }

    private static function proof(string $readAt = '2026-12-01T09:00:00Z'): OrphanProof
    {
        return new OrphanProof(OrphanProof::SOURCE_PMS, OrphanProof::NEXT, 4, 1, 0, readAt: new DateTimeImmutable($readAt));
    }

    /**
     * @param list<PmsChangeEntry> $targets
     * @param array<string, mixed> $o
     */
    private static function context(array $targets, array $o = []): PmsCheckContext
    {
        $room = new RoomType('p1', 'STD', 'Standard', 4, '902001', 'L1', 500, 300, 900, 300);
        $inventory = ['STD' => [
            '2026-12-03' => new InventoryNight(Days::date('2026-12-03'), 1, 1, $o['maxStay'] ?? null, Restriction::None),
            '2026-12-04' => new InventoryNight(Days::date('2026-12-04'), $o['nextAvailable'] ?? 0),
        ]];
        $ruleTargets = [];
        foreach ($o['notTarget'] ?? false ? [] : $targets as $entry) {
            $ruleTargets[$entry->identity()] = true;
        }
        $reverts = new RevertLedger();
        for ($i = 0; $i < ($o['reverts'] ?? 0); ++$i) {
            $reverts = $reverts->observe('STD', Days::date('2026-12-03'), new NightFields([ChangeField::PMS_MIN_STAY => 2]), new NightFields([ChangeField::PMS_MIN_STAY => 3]), new NightFields([ChangeField::PMS_MIN_STAY => 3]));
        }

        return new PmsCheckContext(
            Days::date(self::TODAY),
            new DateTimeImmutable('2026-12-01T12:00:00Z'),
            ['STD' => $room],
            $inventory,
            ($o['frozen'] ?? false) ? ['STD|2026-12-03' => true] : [],
            $ruleTargets,
            $o['accepted'] ?? [],
            ['o-1' => true],
            $reverts,
            14,
            24,
            new StayWriteConfig(true, 60, $o['max'] ?? 300, 2, false),
        );
    }
}
