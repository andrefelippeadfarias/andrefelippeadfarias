<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Update;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Update\ManifestVerifier;
use Ritmo\Application\Update\PackageVerifier;
use Ritmo\Application\Update\TrustedKeys;
use Ritmo\Application\Update\UpdateSettings;
use Ritmo\Connectors\Licence\ServerUrl;
use Ritmo\Tests\Support\Fixtures;

/**
 * bin/sign-release.php as the publisher runs it: a key pair outside the repository, a signed
 * release the plugin accepts, the private key never printed nor written into the repository.
 */
final class SignReleaseScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ritmo-sign-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private static function script(array $args, array $env = []): array
    {
        $process = proc_open(
            [PHP_BINARY, Fixtures::pluginRoot() . '/bin/sign-release.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['PATH' => (string) getenv('PATH')]
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testKeygenSignAndVerifyMakeAReleaseThePluginAccepts(): void
    {
        [$code, $public, $err] = self::script(['keygen', '--out', $this->dir . '/signing.key']);
        self::assertSame(0, $code, $err);
        $public = trim($public);
        $secret = trim((string) file_get_contents($this->dir . '/signing.key'));
        self::assertSame(0600, fileperms($this->dir . '/signing.key') & 0777);
        self::assertSame(32, strlen((string) base64_decode($public, true)));
        self::assertStringNotContainsString($secret, $public . $err, 'the secret key is never printed');

        file_put_contents($this->dir . '/package.zip', "PK\x03\x04 release 1.3.0");
        file_put_contents($this->dir . '/changes.txt', "Licence screen.\n\nSigned updates.\n");
        $package = 'https://updates.example.com/ritmo-revenue/ritmo-revenue-1.3.0.zip';
        [$code, $out, $err] = self::script(
            ['sign', '--zip', $this->dir . '/package.zip', '--version', '1.3.0', '--package-url', $package, '--requires', '6.5', '--requires-php', '8.1', '--changelog', $this->dir . '/changes.txt', '--out', $this->dir . '/latest.json'],
            ['RITMO_SIGNING_KEY' => $secret]
        );
        self::assertSame(0, $code, $err);
        $metadata = (string) file_get_contents($this->dir . '/latest.json');
        self::assertStringNotContainsString($secret, $metadata . $out . $err);

        $settings = new UpdateSettings(ServerUrl::parse('https://updates.example.com/ritmo-revenue/latest.json', false), TrustedKeys::parse($public), '1.2.0');
        $manifest = (new ManifestVerifier($settings))->verify($metadata);
        self::assertSame('1.3.0', $manifest->version);
        self::assertSame(['Licence screen.', 'Signed updates.'], $manifest->changelog);
        PackageVerifier::verify((string) file_get_contents($this->dir . '/package.zip'), $manifest, $settings->keys);

        [$code, $out] = self::script(['verify', '--metadata', $this->dir . '/latest.json', '--zip', $this->dir . '/package.zip', '--public-key', $public, '--metadata-url', 'https://updates.example.com/ritmo-revenue/latest.json']);
        self::assertSame(0, $code);
        self::assertStringContainsString('OK: version 1.3.0', $out);

        file_put_contents($this->dir . '/package.zip', "PK\x03\x04 release 1.3.1");
        [$code, , $err] = self::script(['verify', '--metadata', $this->dir . '/latest.json', '--zip', $this->dir . '/package.zip', '--public-key', $public, '--metadata-url', 'https://updates.example.com/ritmo-revenue/latest.json']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Refused (hash)', $err);

        [$code, $out] = self::script(['public-key', '--key-file', $this->dir . '/signing.key']);
        self::assertSame(0, $code);
        self::assertSame($public, trim($out));
    }

    public function testNoKeyIsEverWrittenToOrReadFromTheRepository(): void
    {
        $inside = Fixtures::pluginRoot() . '/signing-' . bin2hex(random_bytes(4)) . '.key';

        [$code, , $err] = self::script(['keygen', '--out', $inside]);
        self::assertSame(1, $code);
        self::assertStringContainsString('outside the repository', $err);
        self::assertFileDoesNotExist($inside);

        [$code, , $err] = self::script(['sign', '--zip', __FILE__, '--version', '1.0.0', '--package-url', 'https://u.example.com/x.zip', '--key-file', Fixtures::pluginRoot() . '/README.md']);
        self::assertSame(1, $code);
        self::assertStringContainsString('outside the repository', $err);
    }

    public function testSigningWithoutAKeyOrOverAnExistingKeyFileIsRefused(): void
    {
        [$code, , $err] = self::script(['sign', '--zip', __FILE__, '--version', '1.0.0', '--package-url', 'https://u.example.com/x.zip']);
        self::assertSame(1, $code);
        self::assertStringContainsString('RITMO_SIGNING_KEY', $err);

        file_put_contents($this->dir . '/existing.key', 'keep me');
        [$code] = self::script(['keygen', '--out', $this->dir . '/existing.key']);
        self::assertSame(1, $code);
        self::assertSame('keep me', file_get_contents($this->dir . '/existing.key'));

        [$code] = self::script(['unknown']);
        self::assertSame(2, $code);
    }
}
