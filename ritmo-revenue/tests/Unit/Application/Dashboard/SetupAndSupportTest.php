<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Dashboard;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Dashboard\Connections\ConnectionReason;
use Ritmo\Application\Dashboard\Connections\ConnectionTests;
use Ritmo\Application\Dashboard\Diagnostics\SupportBundle;
use Ritmo\Application\Dashboard\Setup\RoomRowsRefused;
use Ritmo\Application\Dashboard\Setup\SetupState;
use Ritmo\Application\Dashboard\Setup\SetupWizard;
use Ritmo\Application\Dashboard\Setup\WizardProgress;
use Ritmo\Application\Dashboard\Setup\WouldDo;
use Ritmo\Application\Grid\LastPlan;
use Ritmo\Application\Setup\SetupService;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PriceSampleRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Tests\Unit\Application\Grid\ScreenWorld;

final class SetupAndSupportTest extends TestCase
{
    public function testTheWizardStepsAreDerivedFromFacts(): void
    {
        $fresh = new WizardProgress([]);
        self::assertSame(2, $fresh->current());
        self::assertTrue($fresh->done(1));
        self::assertFalse($fresh->complete());

        $all = new WizardProgress(['pms_tested' => true, 'pricing_tested' => true, 'has_property' => true, 'rooms_complete' => true, 'rules_chosen' => true, 'safety_confirmed' => false, 'shadow_run_done' => true]);
        self::assertSame(7, $all->current());
        self::assertSame(9, (new WizardProgress(['pms_tested' => true, 'pricing_tested' => true, 'has_property' => true, 'rooms_complete' => true, 'rules_chosen' => true, 'safety_confirmed' => true, 'shadow_run_done' => true]))->current());
    }

    public function testAWizardRunFromNothingToTheSafetyStep(): void
    {
        $world = new ScreenWorld();
        $wizard = $this->wizard($world, true);
        self::assertSame(2, $wizard->progress(null)->current());

        $tests = new ConnectionTests($world->settings, $world->clock);
        $tests->record('beds24', new ConnectionStatus(true, 'ok', ['properties' => 1]), 1);
        $tests->record('pricelabs', new ConnectionStatus(true, 'ok', ['listings' => 3, 'account_listings' => 3, 'pms' => 'beds24']), 1);
        self::assertSame(4, $wizard->progress(null)->current());

        $world->property();
        $saved = $wizard->saveRooms([$this->roomRow('A'), $this->roomRow('B')]);
        self::assertSame(2, $saved);
        self::assertSame(6, $wizard->progress('inn')->current());

        $version = $wizard->chooseRules([4, 5], 1);
        self::assertSame([4, 5], $wizard->rules()->weekendDays());
        self::assertSame(7, $wizard->progress('inn')->current());

        $wizard->confirmSafety('inn', ['floors.fraction_of_reference_min' => 0.8, 'safety.weekly_listing_change_max' => 0.1], ['A' => 450.0], 1);
        self::assertSame(0.8, $wizard->rules()->get('floors.fraction_of_reference_min'));
        self::assertSame(450.0, $world->roomTypes()->find('A')?->referenceMin);
        self::assertGreaterThan($version, (new RuleSetRepository($world->raw, $world->clock))->currentVersion());
        self::assertSame(8, $wizard->progress('inn')->current());

        $wizard->chooseRules([4, 5, 6], 1);
        self::assertSame(8, $wizard->progress('inn')->current(), 'a change outside the safety values keeps the confirmation');
        $wizard->confirmSafety('inn', ['floors.fraction_of_reference_min' => 0.7], [], 1);
        $wizard->chooseRules([4, 5], 1);
        self::assertSame(8, $wizard->progress('inn')->current());
    }

    public function testOneInvalidRoomRefusesTheWholeStep(): void
    {
        $world = new ScreenWorld();
        $world->property();
        $wizard = $this->wizard($world, true);
        try {
            $wizard->saveRooms([$this->roomRow('A'), ['property_id' => 'inn', 'code' => 'bad code!'] + $this->roomRow('B')]);
            self::fail('the step was not refused');
        } catch (RoomRowsRefused $refused) {
            self::assertSame(2, $refused->errors[0]['row']);
        }
        self::assertSame([], $world->roomTypes()->forProperty('inn'), 'nothing saved');
    }

    public function testTheSafetyStepNeedsTheSafetyCapability(): void
    {
        $world = new ScreenWorld();
        $world->property();

        $this->expectException(\InvalidArgumentException::class);
        $this->wizard($world, false)->confirmSafety('inn', ['floors.fraction_of_reference_min' => 0.5], [], 2);
    }

    public function testWhatTheShadowRunWouldDo(): void
    {
        $world = new ScreenWorld();
        $world->property();
        $world->room('A');
        $world->run(['A'], 20, ['A|2026-11-20' => 'would_write', 'A|2026-11-15' => 'would_write', 'A|2026-11-18' => 'rejected']);

        $summary = (new WouldDo(new LastPlan(new RunRepository($world->db), new PlanItemRepository($world->db, $world->clock))))->summary('inn');

        self::assertSame(2, $summary['total']);
        self::assertSame(2, $summary['down']);
        self::assertSame('2026-11-15', $summary['nearest'][0]['night']);
        self::assertSame('2026-11-18', $summary['refused'][0]['night']);
    }

    public function testConnectionReasonsAndTheCooldown(): void
    {
        self::assertSame('auth', ConnectionReason::of(new ConnectionStatus(false, 'HTTP 401 {"error":"x"}', ['http_status' => 401])));
        self::assertSame('rate_limited', ConnectionReason::of(new ConnectionStatus(false, 'slow', ['http_status' => 429])));
        self::assertSame('network', ConnectionReason::of(new ConnectionStatus(false, 'Connection refused')));
        self::assertSame('not_configured', ConnectionReason::of(new ConnectionStatus(false, 'The Beds24 connection is not configured.')));
        self::assertSame('pms_mismatch', ConnectionReason::of(new ConnectionStatus(true, 'ok', ['listings' => 0, 'account_listings' => 4])));
        self::assertSame('no_properties', ConnectionReason::of(new ConnectionStatus(true, 'ok', ['properties' => 0])));
        self::assertSame('forbidden', ConnectionReason::of(new ConnectionStatus(false, 'x', ['reason' => 'forbidden', 'http_status' => 401])));
        self::assertSame(['listings' => 3, 'pms' => 'beds24'], ConnectionReason::counts(new ConnectionStatus(true, 'ok', ['listings' => 3, 'pms' => 'beds24', 'property_names' => 'Some Inn'])));

        $world = new ScreenWorld();
        $tests = new ConnectionTests($world->settings, $world->clock);
        self::assertNull($tests->withinCooldown('beds24'));
        $tests->record('beds24', new ConnectionStatus(false, 'HTTP 401', ['http_status' => 401]), 5);
        self::assertSame('auth', $tests->withinCooldown('beds24')['reason'] ?? null);
        self::assertSame(false, $world->settings->get('connection_test_beds24')['ok'] ?? null, 'ModePreconditions reads the same setting');
    }

    public function testTheSupportFileHasOnlyTheAllowedKeysAndNoSecretOrEmail(): void
    {
        $bundle = SupportBundle::build([
            'versions' => ['plugin' => '0.4.0'],
            'settings' => ['beds24_secret' => 'abc', 'pricelabs_pms' => 'beds24', 'alert_recipients' => 'owner@example.com', 'empty_token' => ''],
            'log' => [['message' => 'Mail sent to someone@example.org at 10:00']],
            'bookings' => [['ref' => '1234']],
            'runs' => [['kind' => 'shadow', 'object' => new \stdClass()]],
        ]);

        self::assertSame(SupportBundle::KEYS, array_keys($bundle));
        self::assertSame(['beds24_secret' => 'saved', 'pricelabs_pms' => 'beds24', 'alert_recipients' => 'saved', 'empty_token' => 'not saved'], $bundle['settings']);
        $json = (string) json_encode($bundle);
        self::assertStringNotContainsString('@example', $json);
        self::assertStringNotContainsString('1234', $json);
        self::assertNull($bundle['runs'][0]['object']);
    }

    /**
     * @return array<string, string>
     */
    private function roomRow(string $code): array
    {
        return ['property_id' => 'inn', 'code' => $code, 'name' => 'Room ' . $code, 'units' => '2', 'pms_ref' => '10' . strlen($code), 'pricing_ref' => 'pl-' . $code, 'base' => '600', 'min' => '400', 'max' => '1200', 'reference_min' => '400', 'tags' => ''];
    }

    private function wizard(ScreenWorld $world, bool $safety): SetupWizard
    {
        $ruleSets = new RuleSetRepository($world->raw, $world->clock);
        $setup = new SetupService($world->raw, $world->properties(), $world->roomTypes(), $ruleSets, new PriceSampleRepository($world->raw), new MarketAnchorRepository($world->raw, $world->clock), $world->clock);
        $capabilities = new class ($safety) implements CapabilityCheck {
            public function __construct(private readonly bool $safety)
            {
            }

            public function userCan(int $userId, string $capability): bool
            {
                return $capability !== SetupWizard::MANAGE_SAFETY || $this->safety;
            }
        };

        return new SetupWizard($world->raw, $world->properties(), $world->roomTypes(), $ruleSets, new RunRepository($world->raw), $setup, new ConnectionTests($world->settings, $world->clock), new SetupState($world->settings), $capabilities, $world->clock);
    }
}
