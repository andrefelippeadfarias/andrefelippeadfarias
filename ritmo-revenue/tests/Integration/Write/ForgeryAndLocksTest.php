<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write;

use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Application\Write\Gate\AutonomousAuthorization;
use Ritmo\Application\Write\Gate\WriteRefused;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\WriteRouteAllowList;
use Ritmo\Tests\Integration\Write\Support\SimWorld;

/**
 * No write leaves without a live token issued by the gate for exactly that request: not with a
 * token forged by reflection, unserialised or cloned; not outside the token's steps nor twice; not
 * through a read-only connector or the read door of the HTTP client; and the PMS never receives a
 * price nor a booking route, refused before anything is sent.
 */
final class ForgeryAndLocksTest extends TestCase
{
    private SimWorld $world;

    protected function setUp(): void
    {
        $this->world = new SimWorld();
    }

    private function blocked(callable $write): void
    {
        try {
            $write();
            self::fail('the write was allowed');
        } catch (WriteBlocked) {
            $this->addToAssertionCount(1);
        }
    }

    public function testATokenForgedByReflectionIsRefusedByTheConnectorsAndTheGuard(): void
    {
        $forged = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();
        $rule = ['date' => '2027-03-10', 'min_price' => '-6', 'min_price_type' => 'percent_base', 'currency' => 'BRL'];

        $this->blocked(fn () => $this->world->pricing->writeOverrides($forged, SimWorld::STD, [$rule]));
        $this->blocked(fn () => $this->world->pricing->deleteOverrides($forged, SimWorld::STD, [Days::date('2027-03-10')]));
        $this->blocked(fn () => $this->world->pricing->requestRecalculation($forged, SimWorld::STD));
        $this->blocked(fn () => $this->world->pms->writeCalendar($forged, [['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'minStay' => 2]]));
        $this->blocked(fn () => $this->world->http->write($forged, 'POST', 'https://api.pricelabs.co/v1/listings/' . SimWorld::STD . '/overrides', [], '{}'));

        self::assertSame([], $this->world->apis->writeLines(), 'the simulator received nothing');
    }

    public function testATokenCannotBeRebuiltNorCopied(): void
    {
        $issued = $this->world->issue($this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]));
        try {
            unserialize(sprintf('O:%d:"%s":1:{s:2:"id";s:4:"fake";}', strlen(WriteToken::class), WriteToken::class));
            self::fail('a token was unserialised');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(Error::class);
        $copy = clone $issued->token;
        self::assertNull($copy);
    }

    public function testALiveTokenAllowsOnlyItsStepsAndEachOnce(): void
    {
        $issued = $this->world->issue($this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]));
        $step = $issued->steps[0];

        $this->blocked(fn () => $this->world->pricing->writeOverrides($issued->token, SimWorld::GDN, $step->payload));
        $this->blocked(fn () => $this->world->pricing->writeOverrides($issued->token, SimWorld::STD, [['date' => '2027-03-11'] + $step->payload[0]]));
        $this->blocked(fn () => $this->world->pricing->deleteOverrides($issued->token, SimWorld::STD, [Days::date('2027-03-10')]));
        self::assertSame([], $this->world->apis->writeLines());

        $this->world->pricing->writeOverrides($issued->token, SimWorld::STD, $step->payload);
        $this->blocked(fn () => $this->world->pricing->writeOverrides($issued->token, SimWorld::STD, $step->payload));
        self::assertCount(1, $this->world->apis->writeLines(), 'the same step goes once');
    }

    public function testThePmsNeverReceivesAPriceNorABookingRoute(): void
    {
        $night = Days::date('2027-03-12');
        $issued = $this->world->issue(
            $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]),
            [new CalendarChange('STD', '902001', $night, $night, minStay: 2)]
        );
        $token = $issued->token;

        $this->blocked(fn () => $this->world->pms->writeCalendar($token, [['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'minStay' => 2, 'price1' => 500]]));
        $this->blocked(fn () => $this->world->pms->writeCalendar($token, [['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'numAvail' => 5]]));
        $this->blocked(fn () => $this->world->http->write($token, 'POST', 'https://api.beds24.com/v2/bookings', [], '[{"roomId":902001}]'));
        $this->blocked(fn () => $this->world->http->write($token, 'DELETE', 'https://api.beds24.com/v2/bookings', [], '[{"id":7001}]'));
        $this->blocked(fn () => $this->world->http->request('POST', 'https://api.beds24.com/v2/inventory/rooms/calendar', [], '[]'));
        self::assertSame([], $this->world->apis->writeLines(), 'refused before anything left');

        $this->world->pms->writeCalendar($token, [['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'minStay' => 2]]);
        self::assertSame(['POST /beds24/v2/inventory/rooms/calendar'], $this->world->apis->writeLines(), 'the refusals used up nothing of the token');

        $this->expectException(InvalidArgumentException::class);
        WriteRouteAllowList::none()->with('beds24', 'POST', 'api.beds24.com', '/v2/bookings');
    }

    public function testAConnectorOrAClientBuiltReadOnlyNeverWrites(): void
    {
        $issued = $this->world->issue($this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]));
        $readOnlyHttp = new GuardedHttpClient($this->world->apis, SimWorld::readRoutes(), $this->world->logger);
        $readOnly = new Beds24Connector($readOnlyHttp, new Beds24Config(Beds24Credentials::longLifeToken('sim-long-life-token-0001'), [], [901001]), $this->world->clock, $this->world->logger);

        $this->blocked(fn () => $readOnly->writeCalendar($issued->token, [['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'minStay' => 2]]));
        $this->blocked(fn () => $readOnlyHttp->write($issued->token, 'POST', 'https://api.pricelabs.co/v1/listings/' . SimWorld::STD . '/overrides', [], '{}'));
        self::assertSame([], $this->world->apis->writeLines());
    }

    public function testAShadowPropertySendsNoWriteAtAll(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);

        try {
            $this->world->gate->issue($plan, new AutonomousAuthorization('inn', 1, $this->world->clock->now()), $this->world->context(PropertyMode::Shadow));
            self::fail('a shadow property got a token');
        } catch (WriteRefused $refused) {
            self::assertSame('mode_shadow', $refused->reason);
        }
        self::assertSame([], $this->world->apis->writeLines());
    }
}
