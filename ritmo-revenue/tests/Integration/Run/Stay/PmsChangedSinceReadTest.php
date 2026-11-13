<?php
declare(strict_types=1);
namespace Ritmo\Tests\Integration\Run\Stay;

use PHPUnit\Framework\TestCase;

/**
 * Regression: an arrival/departure change of the stay writes is skipped (changed_since_read) when
 * the PMS changed that very field after the run read it, as the minimum stay always was.
 */
final class PmsChangedSinceReadTest extends TestCase
{
    public function testARestrictionChangedByAPersonAfterTheReadIsOverwritten(): void
    {
        $world = StayWorld::auto(['closed_days' => [StayWorld::CLOSED_SATURDAY]]);
        $done = false;
        $world->hook->afterWrite = static function (int $n) use ($world, &$done): void {
            if (!$done) {
                $done = true;
                // the owner opens the arrival of Saturday 6 March by hand, while the run is writing prices
                StayWorld::thirdParty($world, 902001, '2027-03-06', ['override' => 'noCheckOut']);
            }
        };
        $world->run();
        self::assertTrue($done);
        self::assertSame('noCheckOut', StayWorld::pmsNight($world, '902001', '2027-03-06')['override'] ?? null, 'the person\'s change since the read must survive (changed_since_read)');
    }

    /** Control: the same race on the minimum stay is caught (changed_since_read). */
    public function testControlAMinimumStayChangedSinceTheReadIsSkipped(): void
    {
        $world = StayWorld::auto(['arrival_rule' => ['enabled' => true, 'by_weekday' => [2, 2, 2, 2, 2, 2, 2]]]);
        $done = false;
        $world->hook->afterWrite = static function (int $n) use ($world, &$done): void {
            if (!$done) {
                $done = true;
                StayWorld::thirdParty($world, 902001, '2027-03-10', ['minStay' => 4]);
            }
        };
        $world->run();
        self::assertSame(4, StayWorld::pmsNight($world, '902001', '2027-03-10')['minStay'] ?? null);
        $plain = StayWorld::auto(['arrival_rule' => ['enabled' => true, 'by_weekday' => [2, 2, 2, 2, 2, 2, 2]]]);
        $plain->run();
        self::assertSame(2, StayWorld::pmsNight($plain, '902001', '2027-03-10')['minStay'] ?? null, 'without the race the night is written');
    }
}
