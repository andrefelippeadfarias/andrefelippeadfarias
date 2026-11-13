<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use Ritmo\WordPress\Roles;

final class RolesTest extends TestCase
{
    private const FOUR = [Roles::VIEW, Roles::MANAGE_RULES, Roles::APPROVE, Roles::MANAGE_CONNECTIONS];

    public function testAFreshSiteGetsBothRolesAndAdministratorsGetEveryCapability(): void
    {
        self::assertSame([
            'create' => true,
            'grant' => self::FOUR,
            'administrator' => Roles::CAPABILITIES,
            'create_viewer' => true,
            'viewer' => [Roles::VIEW],
        ], Roles::pending(0, false, false));
    }

    public function testASiteAtVersionOneGetsOnlyTheSafetyCapabilityForAdministratorsAndTheViewerRole(): void
    {
        self::assertSame([
            'create' => false,
            'grant' => [],
            'administrator' => [Roles::MANAGE_SAFETY],
            'create_viewer' => true,
            'viewer' => [Roles::VIEW],
        ], Roles::pending(1, true, false));
    }

    public function testTheManagerRoleNeverReceivesTheSafetyCapability(): void
    {
        foreach (Roles::GRANTS as $grants) {
            self::assertNotContains(Roles::MANAGE_SAFETY, $grants['manager']);
            self::assertSame([], array_diff($grants['viewer'], [Roles::VIEW]), 'the viewer only sees');
        }
    }

    public function testAnUpgradeGrantsNothingTheSiteAlreadyReceived(): void
    {
        $latest = max(array_keys(Roles::GRANTS));

        $nothing = ['create' => false, 'grant' => [], 'administrator' => [], 'create_viewer' => false, 'viewer' => []];
        self::assertSame($nothing, Roles::pending($latest, true, true), 'a capability the site removed stays removed');
        self::assertSame($nothing, Roles::pending($latest, false, false), 'a role the site deleted is not created again');
    }

    public function testASiteFromBeforeTheVersionsKeepsItsRoleAndIsGrantedOnce(): void
    {
        $step = Roles::pending(0, true, false);

        self::assertFalse($step['create']);
        self::assertSame(self::FOUR, $step['grant']);
    }

    public function testEveryCapabilityIsGrantedToAdministratorsInExactlyOneVersion(): void
    {
        $granted = array_merge(...array_map(static fn (array $g): array => $g['administrator'], array_values(Roles::GRANTS)));
        sort($granted);
        $all = Roles::CAPABILITIES;
        sort($all);

        self::assertSame($all, $granted);
        self::assertSame(range(1, count(Roles::GRANTS)), array_keys(Roles::GRANTS), 'versions are 1, 2, 3...');
    }
}
