<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Secrets;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Secrets\SecretStatus;
use Ritmo\Infrastructure\Secrets\SecretUnavailable;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Unit\Infrastructure\Support\ManualClock;

final class SodiumVaultTest extends TestCase
{
    private const API_KEY = 'pk-live-3f9a1c77d2e04b55a8c6';
    private const KEY_A = 'site-key-A-0123456789abcdef0123456789abcdef';
    private const KEY_B = 'site-key-B-fedcba9876543210fedcba9876543210';

    private ArrayStore $store;

    private ManualClock $clock;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
        $this->clock = new ManualClock('2026-11-11T12:00:00Z');
    }

    public function testASecretIsEncryptedAndComesBackOnlyThroughReveal(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('pricing.api_key', self::API_KEY);

        self::assertSame(self::API_KEY, $vault->reveal('pricing.api_key'));
        self::assertTrue($vault->has('pricing.api_key'));
        self::assertSame(['pricing.api_key'], $vault->names());
        self::assertStringNotContainsString(self::API_KEY, (string) json_encode($this->store->all()));
        self::assertStringNotContainsString(base64_encode(self::API_KEY), (string) json_encode($this->store->all()));
    }

    public function testTheSameSecretNeverEncryptsToTheSameBytes(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('a', self::API_KEY);
        $first = $this->store->get('vault')['entries']['a']['c'];
        $vault->put('a', self::API_KEY);

        self::assertNotSame($first, $this->store->get('vault')['entries']['a']['c']);
    }

    public function testTheInterfaceOnlyLearnsWhetherASecretExistsAndItsLastFourCharacters(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        self::assertEquals(new SecretStatus(false), $vault->status('pricing.api_key'));

        $vault->put('pricing.api_key', self::API_KEY);
        $status = $vault->status('pricing.api_key');

        self::assertTrue($status->configured);
        self::assertSame('a8c6', $status->lastFour);
        self::assertSame('2026-11-11T12:00:00+00:00', $status->updatedAt?->format(DATE_ATOM));
        self::assertSame(['configured', 'lastFour', 'updatedAt'], array_keys(get_object_vars($status)), 'the status has no room for the secret');
    }

    public function testAShortSecretShowsNoCharactersAtAll(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('short', 'abcdefghijk');
        $vault->put('long-enough', 'abcdefghijkl');

        self::assertSame('', $vault->status('short')->lastFour);
        self::assertTrue($vault->status('short')->configured);
        self::assertSame('ijkl', $vault->status('long-enough')->lastFour);
    }

    public function testTheVaultNeverPrintsItsKeyOrASecret(): void
    {
        $vault = new SodiumVault($this->store, $this->clock, self::KEY_A);
        $vault->put('pricing.api_key', self::API_KEY);

        ob_start();
        var_dump($vault);
        $dump = (string) ob_get_clean() . print_r($vault, true) . var_export($vault, true) . var_export($vault->status('pricing.api_key'), true);

        self::assertFalse(str_contains($dump, self::KEY_A), 'a dump of the vault shows the configured key');
        self::assertFalse(str_contains($dump, self::API_KEY), 'a dump of the vault shows a secret');
        self::assertTrue(str_contains($dump, 'uses_configured_key'));
    }

    public function testTheVaultCannotBeSerialized(): void
    {
        $vault = new SodiumVault($this->store, $this->clock, self::KEY_A);

        $this->expectException(LogicException::class);
        serialize($vault);
    }

    public function testAFailureInsideTheVaultDoesNotCarryTheKeyOrTheSecretInItsTrace(): void
    {
        try {
            (new SodiumVault($this->store, $this->clock, self::KEY_A))->put('Bad Name', self::API_KEY);
            self::fail('The bad name must be refused.');
        } catch (InvalidArgumentException $failure) {
            $trace = print_r($failure->getTrace(), true) . $failure->getTraceAsString() . $failure->getMessage();
            self::assertFalse(str_contains($trace, self::API_KEY), 'the trace carries the secret');
            self::assertFalse(str_contains($trace, self::KEY_A), 'the trace carries the configured key');
        }
    }

    public function testAnUnknownSecretIsReportedWithoutLeakingAnything(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);

        self::assertFalse($vault->has('pms.token'));
        $this->expectException(SecretUnavailable::class);
        $this->expectExceptionMessage('The secret "pms.token" is not configured.');
        $vault->reveal('pms.token');
    }

    public function testASecretCanBeReplacedAndForgotten(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('pms.token', 'first-token-0000001');
        $vault->put('pms.token', 'second-token-000002');
        self::assertSame('second-token-000002', $vault->reveal('pms.token'));

        $vault->forget('pms.token');
        $vault->forget('never-there');

        self::assertFalse($vault->has('pms.token'));
        self::assertSame([], $vault->names());
    }

    public function testEmptySecretsAndBadNamesAreRefused(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);

        foreach ([['pms.token', ''], ['', 'x'], ['Has Space', 'x'], ['UPPER', 'x'], [str_repeat('a', 65), 'x']] as [$name, $secret]) {
            try {
                $vault->put($name, $secret);
                self::fail("put('$name') must be refused.");
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $vault->names());
    }

    public function testWithoutAConfiguredKeyARandomSeedIsCreatedOnceAndKept(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->install();
        $seed = $this->store->get('vault')['seed'];

        self::assertSame(32, strlen((string) base64_decode((string) $seed, true)));

        $vault->install();
        $vault->put('pms.token', 'token-abcdefghijkl');

        self::assertSame($seed, $this->store->get('vault')['seed']);
        self::assertSame('token-abcdefghijkl', (new SodiumVault($this->store, $this->clock))->reveal('pms.token'), 'another request reads it with the stored seed');
        self::assertNotSame($seed, (function (): string {
            $other = new ArrayStore();
            (new SodiumVault($other, new ManualClock()))->install();

            return $other->get('vault')['seed'];
        })(), 'every site gets its own seed');
    }

    public function testWithAConfiguredKeyNoSeedIsStoredAndTheDatabaseAloneCannotDecrypt(): void
    {
        $vault = new SodiumVault($this->store, $this->clock, self::KEY_A);
        $vault->install();
        $vault->put('pricing.api_key', self::API_KEY);

        self::assertNull($this->store->get('vault')['seed']);
        self::assertSame(self::API_KEY, (new SodiumVault($this->store, $this->clock, self::KEY_A))->reveal('pricing.api_key'));

        // Someone with a copy of the stored data but not the configured key.
        $stolen = new ArrayStore($this->store->all());
        $this->expectException(SecretUnavailable::class);
        (new SodiumVault($stolen, $this->clock))->reveal('pricing.api_key');
    }

    public function testTheWrongKeyFails(): void
    {
        (new SodiumVault($this->store, $this->clock, self::KEY_A))->put('pricing.api_key', self::API_KEY);

        $this->expectException(SecretUnavailable::class);
        $this->expectExceptionMessage('encrypted with a key that is no longer available');
        (new SodiumVault($this->store, $this->clock, self::KEY_B))->reveal('pricing.api_key');
    }

    public function testATamperedEntryFailsEvenWithTheRightKey(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('pricing.api_key', self::API_KEY);
        $state = $this->store->get('vault');
        $cipher = (string) base64_decode($state['entries']['pricing.api_key']['c'], true);
        $cipher[5] = $cipher[5] === 'x' ? 'y' : 'x';
        $state['entries']['pricing.api_key']['c'] = base64_encode($cipher);
        $this->store->set('vault', $state);

        $this->expectException(SecretUnavailable::class);
        $this->expectExceptionMessage('damaged');
        $vault->reveal('pricing.api_key');
    }

    public function testAnEntryCopiedOverAnotherNameDoesNotDecrypt(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('pms.token', 'the-pms-token-000001');
        $vault->put('pricing.api_key', self::API_KEY);
        $state = $this->store->get('vault');
        $state['entries']['pms.token'] = $state['entries']['pricing.api_key'];
        $this->store->set('vault', $state);

        $this->expectException(SecretUnavailable::class);
        $vault->reveal('pms.token');
    }

    public function testRotationGivesEverySecretANewKeyAndKeepsItsValue(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('pms.token', 'the-pms-token-000001');
        $vault->put('pricing.api_key', self::API_KEY);
        $before = $this->store->get('vault');
        $this->clock->advance(3600);

        self::assertSame(2, $vault->rotate());

        $after = $this->store->get('vault');
        self::assertNotSame($before['seed'], $after['seed']);
        self::assertNotSame($before['entries']['pms.token']['k'], $after['entries']['pms.token']['k']);
        self::assertNotSame($before['entries']['pms.token']['c'], $after['entries']['pms.token']['c']);
        self::assertSame('the-pms-token-000001', $vault->reveal('pms.token'));
        self::assertSame(self::API_KEY, (new SodiumVault($this->store, $this->clock))->reveal('pricing.api_key'));
        self::assertSame('a8c6', $vault->status('pricing.api_key')->lastFour);

        // The old seed no longer opens anything.
        $old = new ArrayStore(['vault' => ['seed' => $before['seed'], 'entries' => $after['entries']]]);
        $this->expectException(SecretUnavailable::class);
        (new SodiumVault($old, $this->clock))->reveal('pms.token');
    }

    public function testRotationMovesFromTheStoredSeedToAConfiguredKey(): void
    {
        (new SodiumVault($this->store, $this->clock))->put('pricing.api_key', self::API_KEY);

        $configured = new SodiumVault($this->store, $this->clock, self::KEY_A);
        self::assertSame(self::API_KEY, $configured->reveal('pricing.api_key'), 'entries written with the seed stay readable until rotated');
        self::assertSame(1, $configured->rotate());

        self::assertNull($this->store->get('vault')['seed'], 'the seed leaves the database');
        self::assertSame(self::API_KEY, $configured->reveal('pricing.api_key'));
        $this->expectException(SecretUnavailable::class);
        (new SodiumVault($this->store, $this->clock))->reveal('pricing.api_key');
    }

    public function testRotationAfterTheConfiguredKeyChangedNeedsTheOldKey(): void
    {
        (new SodiumVault($this->store, $this->clock, self::KEY_A))->put('pricing.api_key', self::API_KEY);
        $before = $this->store->all();
        $vault = new SodiumVault($this->store, $this->clock, self::KEY_B);

        try {
            $vault->rotate();
            self::fail('Rotation without the old key must fail.');
        } catch (SecretUnavailable) {
            self::assertSame($before, $this->store->all(), 'a failed rotation changes nothing');
        }
        try {
            $vault->rotate('not-the-old-key-not-the-old-key-not');
            self::fail('Rotation with a wrong old key must fail.');
        } catch (SecretUnavailable) {
            self::assertSame($before, $this->store->all());
        }

        self::assertSame(1, $vault->rotate(self::KEY_A));
        self::assertSame(self::API_KEY, $vault->reveal('pricing.api_key'));
        $this->expectException(SecretUnavailable::class);
        (new SodiumVault($this->store, $this->clock, self::KEY_A))->reveal('pricing.api_key');
    }

    public function testRotatingAnEmptyVaultIsHarmless(): void
    {
        self::assertSame(0, (new SodiumVault($this->store, $this->clock))->rotate());
        self::assertSame(0, (new SodiumVault(new ArrayStore(), $this->clock, self::KEY_A))->rotate());
    }

    public function testADamagedSeedIsReplacedAndOnlyItsEntriesAreLost(): void
    {
        $vault = new SodiumVault($this->store, $this->clock);
        $vault->put('pms.token', 'the-pms-token-000001');
        $state = $this->store->get('vault');
        $state['seed'] = 'not a seed';
        $this->store->set('vault', $state);

        $vault->put('pricing.api_key', self::API_KEY);

        self::assertSame(self::API_KEY, $vault->reveal('pricing.api_key'));
        self::assertSame(32, strlen((string) base64_decode((string) $this->store->get('vault')['seed'], true)));
        $this->expectException(SecretUnavailable::class);
        $vault->reveal('pms.token');
    }

    public function testAConfiguredKeyMustBeLongEnough(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SodiumVault($this->store, $this->clock, 'too-short');
    }

    public function testTheSiteVaultReadsTheConstantOnlyWhenItIsDefined(): void
    {
        self::assertFalse(defined('RITMO_SECRET_KEY'), 'the test process must not define the constant');

        $vault = SodiumVault::forSite($this->store, $this->clock);
        $vault->put('pms.token', 'the-pms-token-000001');

        self::assertNotNull($this->store->get('vault')['seed'], 'without the constant the vault uses the stored seed');
        self::assertSame(['uses_configured_key' => false], $vault->__debugInfo());
    }

    public function testGarbageInTheStoreIsAnEmptyVault(): void
    {
        $this->store->set('vault', 'garbage');
        $vault = new SodiumVault($this->store, $this->clock);

        self::assertSame([], $vault->names());
        self::assertFalse($vault->has('pms.token'));
    }
}
