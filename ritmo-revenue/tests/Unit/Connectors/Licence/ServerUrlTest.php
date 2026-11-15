<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Licence;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Licence\ServerUrl;

final class ServerUrlTest extends TestCase
{
    public function testAnHttpsAddressWithAPath(): void
    {
        $url = ServerUrl::parse(' https://Licences.Example.com:8443/api/v1/ ', false);

        self::assertSame('https://licences.example.com:8443/api/v1', $url->url);
        self::assertSame('https://licences.example.com:8443', $url->origin());
        self::assertSame('https://licences.example.com:8443/api/v1/validate', $url->endpoint('validate'));
        self::assertTrue($url->sameOrigin('https://licences.example.com:8443/files/x.zip', false));
        self::assertFalse($url->sameOrigin('https://licences.example.com/files/x.zip', false), 'another port');
        self::assertFalse($url->sameOrigin('https://cdn.example.com:8443/files/x.zip', false), 'another host');
        self::assertFalse($url->sameOrigin('http://licences.example.com:8443/files/x.zip', true), 'another scheme');
    }

    public function testPlainHttpOnlyForTheLocalSimulatorInDevelopmentMode(): void
    {
        self::assertSame('http://127.0.0.1:8092/licence', ServerUrl::parse('http://127.0.0.1:8092/licence', true)->url);
        self::assertSame('http://localhost:8092', ServerUrl::parse('http://localhost:8092', true)->url);
        self::assertNull(ServerUrl::tryParse('http://127.0.0.1:8092/licence', false));
        self::assertNull(ServerUrl::tryParse('http://licences.example.com', true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'empty' => [''],
            'relative' => ['/licence'],
            'ftp' => ['ftp://licences.example.com'],
            'credentials' => ['https://user:pass@licences.example.com'],
            'query' => ['https://licences.example.com/api?key=1'],
            'fragment' => ['https://licences.example.com/api#x'],
            'space' => ['https://licences.example.com/a b'],
            'odd path' => ['https://licences.example.com/a/../b%2f'],
            'backslash' => ['https://licences.example.com\\@evil.example'],
        ];
    }

    #[DataProvider('refused')]
    public function testAddressesThatCouldLeakAreRefused(string $text): void
    {
        self::assertNull(ServerUrl::tryParse($text, true));
        $this->expectException(InvalidArgumentException::class);
        ServerUrl::parse($text, true);
    }

    public function testAnEndpointIsAPlainName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ServerUrl::parse('https://licences.example.com', false)->endpoint('../admin');
    }

    public function testNonTextIsNoAddress(): void
    {
        self::assertNull(ServerUrl::tryParse(null, true));
        self::assertNull(ServerUrl::tryParse(42, true));
        self::assertNull(ServerUrl::tryParse('   ', true));
    }
}
