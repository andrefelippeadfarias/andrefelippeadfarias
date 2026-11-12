<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use Ritmo\WordPress\Roles;

final class RolesTest extends TestCase
{
    public function testAFreshSiteGetsTheRoleWithEveryCapability(): void
    {
        self::assertSame(['create' => true, 'grant' => Roles::CAPABILITIES], Roles::pending(0, false));
    }

    public function testAnUpgradeGrantsNothingTheSiteAlreadyReceived(): void
    {
        $latest = max(array_keys(Roles::GRANTS));

        self::assertSame(['create' => false, 'grant' => []], Roles::pending($latest, true), 'a capability the site removed stays removed');
        self::assertSame(['create' => false, 'grant' => []], Roles::pending($latest, false), 'a role the site deleted is not created again');
    }

    public function testASiteFromBeforeTheVersionsKeepsItsRoleAndIsGrantedOnce(): void
    {
        self::assertSame(['create' => false, 'grant' => Roles::CAPABILITIES], Roles::pending(0, true));
    }

    public function testEveryCapabilityBelongsToExactlyOneVersion(): void
    {
        $granted = array_merge(...array_values(Roles::GRANTS));
        sort($granted);
        $all = Roles::CAPABILITIES;
        sort($all);

        self::assertSame($all, $granted);
        self::assertSame(range(1, count(Roles::GRANTS)), array_keys(Roles::GRANTS), 'versions are 1, 2, 3...');
    }
}
