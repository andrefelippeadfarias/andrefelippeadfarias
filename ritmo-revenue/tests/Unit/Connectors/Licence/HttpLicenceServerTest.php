<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Licence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Licence\HttpLicenceServer;
use Ritmo\Connectors\Licence\NoLicenceServer;
use Ritmo\Connectors\Licence\ServerUrl;
use Ritmo\Connectors\Licence\StubLicenceProvider;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\LicenceState;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\ScriptedHttpClient;
use Ritmo\Tests\Fakes\SignedLicenceAnswers;

final class HttpLicenceServerTest extends TestCase
{
    private const KEY = 'RR-2026-ABCD-EFGH';

    private const SITE = 'https://inn.example.com';

    private const NONCE = '0123456789abcdef0123456789abcdef';

    private ScriptedHttpClient $http;

    private HttpLicenceServer $server;

    protected function setUp(): void
    {
        $this->http = new ScriptedHttpClient();
        $this->server = new HttpLicenceServer($this->http, ServerUrl::parse('https://licences.example.com/api/v1/', false), new MovableClock('2026-11-15T10:00:00Z'), 'ritmo-revenue', '1.0.0', [SignedLicenceAnswers::publicKey()], static fn (): string => self::NONCE);
    }

    public function testAValidAnswerWithAnEndDate(): void
    {
        $this->http->queue(SignedLicenceAnswers::answer('valid', '2027-11-15T00:00:00-03:00', 'Licence active.'));

        $state = $this->server->activate(self::KEY, self::SITE);

        self::assertSame(LicenceState::VALID, $state->status);
        self::assertSame('2027-11-15T03:00:00+00:00', $state->expiresAt?->format(DATE_ATOM));
        self::assertSame('2026-11-15T10:00:00+00:00', $state->checkedAt->format(DATE_ATOM));
        self::assertSame('Licence active.', $state->message);
    }

    public function testTheKeyTravelsOnlyInThePostBody(): void
    {
        $this->http->queue(SignedLicenceAnswers::answer(), SignedLicenceAnswers::answer(), ScriptedHttpClient::json(200, ['status' => 'deactivated']));

        $this->server->activate(self::KEY, self::SITE);
        $this->server->check(self::KEY, self::SITE);
        self::assertTrue($this->server->deactivate(self::KEY, self::SITE));

        self::assertSame(['POST /api/v1/activate', 'POST /api/v1/validate', 'POST /api/v1/deactivate'], $this->http->lines());
        foreach ($this->http->requests() as $request) {
            self::assertStringStartsWith('https://licences.example.com/api/v1/', $request['url']);
            self::assertStringNotContainsString(self::KEY, $request['url']);
            self::assertStringNotContainsString(self::KEY, (string) json_encode($request['headers']));
            $body = json_decode((string) $request['body'], true);
            self::assertSame(['licence_key' => self::KEY, 'site_url' => self::SITE, 'product' => 'ritmo-revenue', 'plugin_version' => '1.0.0'], array_diff_key($body, ['nonce' => true]));
            self::assertContains($body['nonce'] ?? self::NONCE, [self::NONCE]);
            self::assertSame('application/json', $request['headers']['Content-Type']);
            self::assertLessThanOrEqual(15, $request['timeout']);
        }
    }

    /**
     * @return array<string, array{HttpResponse|ConnectorException, string}>
     */
    public static function answers(): array
    {
        return [
            'expired' => [ScriptedHttpClient::json(200, ['status' => 'expired', 'expires_at' => '2026-11-01T00:00:00Z']), LicenceState::EXPIRED],
            'invalid in a 200' => [ScriptedHttpClient::json(200, ['status' => 'invalid']), LicenceState::INVALID],
            '401' => [new HttpResponse(401, [], 'no'), LicenceState::INVALID],
            '403 with json' => [ScriptedHttpClient::json(403, ['status' => 'invalid', 'message' => 'In use on another site.']), LicenceState::INVALID],
            '404' => [new HttpResponse(404), LicenceState::INVALID],
            '410' => [new HttpResponse(410), LicenceState::INVALID],
            'no response' => [new ConnectorException('No response from licences.example.com: timeout.', '', null, true), LicenceState::UNREACHABLE],
            '500' => [ScriptedHttpClient::json(500, ['status' => 'valid']), LicenceState::UNREACHABLE],
            '503' => [new HttpResponse(503), LicenceState::UNREACHABLE],
            '429' => [new HttpResponse(429), LicenceState::UNREACHABLE],
            'html in a 200' => [new HttpResponse(200, ['Content-Type' => 'text/html'], '<html>Maintenance</html>'), LicenceState::UNREACHABLE],
            'unknown status' => [ScriptedHttpClient::json(200, ['status' => 'gold']), LicenceState::UNREACHABLE],
            'a list' => [ScriptedHttpClient::json(200, ['valid']), LicenceState::UNREACHABLE],
            'bad date' => [ScriptedHttpClient::json(200, ['status' => 'valid', 'expires_at' => '2027-02-31T00:00:00Z']), LicenceState::UNREACHABLE],
            'date without zone' => [ScriptedHttpClient::json(200, ['status' => 'valid', 'expires_at' => '2027-01-31 00:00']), LicenceState::UNREACHABLE],
            'redirect' => [new HttpResponse(302, ['Location' => 'https://elsewhere.example.com/']), LicenceState::UNREACHABLE],
        ];
    }

    #[DataProvider('answers')]
    public function testEveryAnswerMapsToAStatusAndNothingThrows(HttpResponse|ConnectorException $reply, string $status): void
    {
        $this->http->queue($reply);

        self::assertSame($status, $this->server->check(self::KEY, self::SITE)->status);
    }

    public function testOnlyAValidAnswerSignedByThePluginsLicenceKeyCounts(): void
    {
        $otherKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $cases = [
            'unsigned' => ScriptedHttpClient::json(200, ['status' => 'valid', 'expires_at' => null, 'message' => 'Licence active.']),
            'signed by another server' => SignedLicenceAnswers::answer('valid', null, 'ok', '2026-11-15T10:00:00Z', $otherKey),
            'a longer end date than signed' => SignedLicenceAnswers::answer('valid', '2027-01-01T00:00:00Z', 'ok', '2026-11-15T10:00:00Z', null, ['expires_at' => '2099-01-01T00:00:00Z']),
            'an old answer replayed' => SignedLicenceAnswers::answer('valid', null, 'ok', '2026-11-15T10:00:00Z', null, ['nonce' => 'ffffffffffffffffffffffffffffffff']),
            'no time of the check' => SignedLicenceAnswers::answer('valid', null, 'ok', 'yesterday'),
            'a bad signature' => SignedLicenceAnswers::answer('valid', null, 'ok', '2026-11-15T10:00:00Z', null, ['signature' => base64_encode(str_repeat('x', 64))]),
        ];
        foreach ($cases as $name => $reply) {
            $this->http->queue($reply);
            $state = $this->server->activate(self::KEY, self::SITE);
            self::assertSame(LicenceState::UNREACHABLE, $state->status, $name);
            self::assertStringContainsString('not signed', $state->message, $name);
        }
        $this->http->queue(SignedLicenceAnswers::answer());
        $other = new HttpLicenceServer($this->http, ServerUrl::parse('https://licences.example.com/api/v1/', false), new MovableClock('2026-11-15T10:00:00Z'), 'ritmo-revenue', '1.0.0', [], static fn (): string => self::NONCE);
        self::assertSame(LicenceState::UNREACHABLE, $other->activate(self::KEY, self::SITE)->status, 'without a public key nothing is valid');
        $this->http->queue(SignedLicenceAnswers::answer());
        self::assertSame(LicenceState::VALID, $this->server->check(self::KEY, self::SITE)->status);
    }

    public function testADeactivationIsConfirmedOnlyByItsAnswer(): void
    {
        $this->http->queue(new HttpResponse(503), ScriptedHttpClient::json(200, ['status' => 'valid']), new ConnectorException('down', '', null, true));

        self::assertFalse($this->server->deactivate(self::KEY, self::SITE));
        self::assertFalse($this->server->deactivate(self::KEY, self::SITE));
        self::assertFalse($this->server->deactivate(self::KEY, self::SITE));
    }

    public function testTheTestProviderAnswersFromTheKeyWithoutNetwork(): void
    {
        $stub = new StubLicenceProvider(new MovableClock('2026-11-15T10:00:00Z'));

        $valid = $stub->activate('TEST-VALID-0001', self::SITE);
        self::assertSame(LicenceState::VALID, $valid->status);
        self::assertSame('2027-11-15', $valid->expiresAt?->format('Y-m-d'));
        self::assertSame(LicenceState::EXPIRED, $stub->check('TEST-EXPIRED-0001', self::SITE)->status);
        self::assertSame(LicenceState::UNREACHABLE, $stub->check('TEST-DOWN-0001', self::SITE)->status);
        self::assertSame(LicenceState::INVALID, $stub->check('RR-2026-ABCD-EFGH', self::SITE)->status);
        self::assertTrue($stub->deactivate('TEST-VALID-0001', self::SITE));
        self::assertFalse($stub->deactivate('TEST-DOWN-0001', self::SITE));
        self::assertSame('stub', $stub->id());
    }

    public function testWithoutAServerNothingIsSentAndTheKeyStaysUnchecked(): void
    {
        $none = new NoLicenceServer(new MovableClock());

        self::assertSame(LicenceState::UNREACHABLE, $none->activate(self::KEY, self::SITE)->status);
        self::assertSame(LicenceState::UNREACHABLE, $none->check(self::KEY, self::SITE)->status);
        self::assertFalse($none->deactivate(self::KEY, self::SITE));
    }
}
