<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Licence;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Licence\LicenceEnforcer;
use Ritmo\Application\Licence\LicenceVerdictSource;
use Ritmo\Application\Licence\ModeLowering;
use Ritmo\Application\Licence\RepositoryModeLowering;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Domain\Licence\LicenceVerdict;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;

final class LicenceEnforcerTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $lowered = [];

    private LicenceVerdict $verdict;

    private function enforcer(): LicenceEnforcer
    {
        $source = new class ($this) implements LicenceVerdictSource {
            public function __construct(private readonly LicenceEnforcerTest $test)
            {
            }

            public function verdict(): LicenceVerdict
            {
                return $this->test->current();
            }
        };
        $lowering = new class ($this) implements ModeLowering {
            public function __construct(private readonly LicenceEnforcerTest $test)
            {
            }

            public function toShadow(Property $property, string $reason): void
            {
                $this->test->record($property->id, $reason);
            }
        };

        return new LicenceEnforcer($source, $lowering, new RecordingLogger());
    }

    public function current(): LicenceVerdict
    {
        return $this->verdict;
    }

    public function record(string $propertyId, string $reason): void
    {
        $this->lowered[] = [$propertyId, $reason];
    }

    private static function property(PropertyMode $mode): Property
    {
        return new Property('inn-one', 'Inn One', 'America/Sao_Paulo', 'BRL', $mode);
    }

    public function testAnActiveOrGraceLicenceKeepsTheModeOfEveryRun(): void
    {
        foreach ([new LicenceVerdict(LicenceStanding::Active, LicenceReason::NONE), new LicenceVerdict(LicenceStanding::Grace, LicenceReason::UNREACHABLE)] as $verdict) {
            $this->verdict = $verdict;
            foreach (PropertyMode::cases() as $mode) {
                self::assertSame($mode, $this->enforcer()->modeAtRunStart(self::property($mode)));
            }
            self::assertNull($this->enforcer()->unmetToLeaveShadow());
            self::assertTrue($this->enforcer()->allowsAi());
        }
        self::assertSame([], $this->lowered);
    }

    public function testALapsedLicenceStartsTheRunInShadowAndLowersThePropertyForGood(): void
    {
        $this->verdict = new LicenceVerdict(LicenceStanding::Lapsed, LicenceReason::EXPIRED);

        self::assertSame(PropertyMode::Shadow, $this->enforcer()->modeAtRunStart(self::property(PropertyMode::Auto)));
        self::assertSame(PropertyMode::Shadow, $this->enforcer()->modeAtRunStart(self::property(PropertyMode::Approval)));
        self::assertSame(PropertyMode::Shadow, $this->enforcer()->modeAtRunStart(self::property(PropertyMode::Shadow)));
        self::assertCount(2, $this->lowered, 'a property already in shadow mode is left alone');
        self::assertStringContainsString('expired', $this->lowered[0][1]);
        self::assertFalse($this->enforcer()->allowsAi());
    }

    public function testWithoutALicenceNoPropertyLeavesShadowMode(): void
    {
        $this->verdict = new LicenceVerdict(LicenceStanding::Unlicensed, LicenceReason::NO_KEY);

        $unmet = $this->enforcer()->unmetToLeaveShadow();
        self::assertNotNull($unmet);
        self::assertSame('licence', $unmet['code']);
        self::assertSame(['standing' => 'unlicensed', 'reason' => 'no_key'], $unmet['params']);
        self::assertSame(PropertyMode::Shadow, $this->enforcer()->modeAtRunStart(self::property(PropertyMode::Approval)));
    }

    public function testTheLoweringIsRecordedAndEndsTheApprovalsLikeAnyLowering(): void
    {
        $db = PdoDatabase::inMemory();
        (new Migrator($db, new ArrayStore(), Migrations::all()))->migrate();
        $clock = new MovableClock('2026-12-20T03:00:00Z');
        $properties = new PropertyRepository($db, $clock);
        $properties->save(self::property(PropertyMode::Approval));
        $events = new OperationEventRepository($db);
        $expired = [];
        $lowering = new RepositoryModeLowering($properties, $events, static function (string $id, string $reason) use (&$expired): int {
            $expired[] = [$id, $reason];

            return 1;
        }, $clock);

        $lowering->toShadow(self::property(PropertyMode::Approval), 'The licence lapsed.');
        $lowering->toShadow(self::property(PropertyMode::Shadow), 'Again.');

        self::assertSame(PropertyMode::Shadow, $properties->find('inn-one')?->mode);
        self::assertSame([['inn-one', 'licence_lapsed']], $expired);
        $recorded = $events->latest('inn-one', OperationEventRepository::MODE);
        self::assertCount(1, $recorded);
        self::assertSame('licence', $recorded[0]['actor']);
        self::assertSame('approval', $recorded[0]['from']);
        self::assertSame('shadow', $recorded[0]['to']);
    }
}
