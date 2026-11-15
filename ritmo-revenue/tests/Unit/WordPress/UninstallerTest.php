<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\WordPress\ConnectorFactory;
use Ritmo\WordPress\Uninstaller;

final class UninstallerTest extends TestCase
{
    public function testDataIsKeptUnlessTheSiteChoseToDeleteIt(): void
    {
        $removed = 0;
        $settings = new ArrayStore();
        $uninstaller = new Uninstaller($settings, static function () use (&$removed): void {
            ++$removed;
        });

        self::assertFalse($uninstaller->run());
        $settings->set(Uninstaller::DELETE_DATA, 'yes');
        self::assertFalse($uninstaller->run(), 'only a stored true deletes');
        self::assertSame(0, $removed);

        $settings->set(Uninstaller::DELETE_DATA, true);
        self::assertTrue($uninstaller->run());
        self::assertSame(1, $removed);
    }

    public function testTheSecretsGoEvenWhenTheDataIsKept(): void
    {
        $settings = new ArrayStore();
        $settings->set(Uninstaller::VAULT, ['seed' => 'x', 'entries' => ['beds24.token' => 'y']]);
        $settings->set('history', 'kept');
        $removed = 0;
        $secrets = 0;
        $uninstaller = new Uninstaller($settings, static function () use (&$removed): void {
            ++$removed;
        }, static function () use (&$secrets, $settings): void {
            ++$secrets;
            $settings->delete(Uninstaller::VAULT);
        });

        self::assertFalse($uninstaller->run());
        self::assertSame([0, 1], [$removed, $secrets]);
        self::assertFalse($settings->has(Uninstaller::VAULT), 'tokens, keys and the seed are gone');
        self::assertSame('kept', $settings->get('history'));
        $vault = (new \ReflectionClassConstant(\Ritmo\Infrastructure\Secrets\SodiumVault::class, 'STORE_KEY'))->getValue();
        self::assertSame(Uninstaller::VAULT, $vault, 'the key is the vault\'s own');
    }

    public function testBeds24PropertyIdsAreWholeNumbersSeparatedByCommas(): void
    {
        self::assertSame(['901001', '42'], ConnectorFactory::propertyIds(' 901001, 42 ,, x, -3, 0'));
        self::assertSame([], ConnectorFactory::propertyIds(''));
    }
}
