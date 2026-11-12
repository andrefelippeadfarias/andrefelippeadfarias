<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Infrastructure\Store\ArrayStore;

final class EmergencySwitchTest extends TestCase
{
    public function testTheOptionEngagesAndReleasesTheSwitch(): void
    {
        $store = new ArrayStore();
        $switch = new EmergencySwitch($store);
        self::assertFalse($switch->isEngaged());

        $switch->engage();
        self::assertTrue($switch->isEngaged());
        self::assertTrue($store->get(EmergencySwitch::KEY));

        $switch->release();
        self::assertFalse($switch->isEngaged());
        $store->set(EmergencySwitch::KEY, 'yes');
        self::assertFalse($switch->isEngaged(), 'only a stored true engages it');
    }

    public function testTheConstantCannotBeReleasedFromTheSite(): void
    {
        $switch = new EmergencySwitch(new ArrayStore(), true);
        $switch->release();

        self::assertTrue($switch->isEngaged());
        self::assertTrue($switch->isEngagedByConstant());
    }

    #[RunInSeparateProcess]
    public function testTheConstantIsReadWhenDefinedAndTruthy(): void
    {
        self::assertFalse(EmergencySwitch::constantIsSet());
        define(EmergencySwitch::CONSTANT, true);
        self::assertTrue(EmergencySwitch::constantIsSet());
    }
}
