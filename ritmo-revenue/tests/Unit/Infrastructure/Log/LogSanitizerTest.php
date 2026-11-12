<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Log;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Infrastructure\Log\LogSanitizer;
use stdClass;

final class LogSanitizerTest extends TestCase
{
    public function testTheUsualContextPassesUntouched(): void
    {
        $context = [
            'run_id' => 12, 'property_id' => 'inn-1', 'room_code' => 'suite', 'night' => '2026-12-24', 'provider' => 'pricing',
            'http_status' => 429, 'duration_ms' => 812.5, 'attempt' => 2, 'retryable' => true, 'detail' => null,
            'lock_key' => 'run:inn-1', 'dedupe_key' => 'sync:inn-1', 'counts' => ['bookings' => 14, 'nights' => 60],
        ];

        self::assertSame($context, (new LogSanitizer())->context($context));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretKeys(): array
    {
        return [
            ['api_key'], ['apiKey'], ['X-API-Key'], ['token'], ['refreshToken'], ['access_token'], ['client_secret'], ['password'],
            ['passwd'], ['Authorization'], ['authorisation'], ['credentials'], ['Cookie'], ['signature'], ['private_key'],
        ];
    }

    #[DataProvider('secretKeys')]
    public function testValuesUnderCredentialKeysAreRedacted(string $key): void
    {
        $clean = (new LogSanitizer())->context([$key => 'pk-live-3f9a1c77', 'nested' => ['headers' => [$key => ['pk-live-3f9a1c77']]]]);

        self::assertSame(LogSanitizer::REDACTED, $clean[$key]);
        self::assertSame(LogSanitizer::REDACTED, $clean['nested']['headers'][$key]);
        self::assertStringNotContainsString('3f9a1c77', (string) json_encode($clean));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guestKeys(): array
    {
        return [
            ['guest'], ['guestName'], ['guest_first_name'], ['firstName'], ['first_name'], ['lastName'], ['last_name'], ['full_name'],
            ['surname'], ['email'], ['e-mail'], ['guest_email'], ['phone'], ['mobile'], ['address'], ['street'], ['postcode'], ['zip'],
            ['passport'], ['document'], ['birth_date'], ['comments'], ['notes'], ['note'],
        ];
    }

    #[DataProvider('guestKeys')]
    public function testValuesUnderGuestKeysAreOmitted(string $key): void
    {
        $clean = (new LogSanitizer())->context([$key => 'Maria Example', 'items' => [[$key => 'Maria Example']]]);

        self::assertSame(LogSanitizer::OMITTED, $clean[$key]);
        self::assertSame(LogSanitizer::OMITTED, $clean['items'][0][$key]);
        self::assertStringNotContainsString('Maria', (string) json_encode($clean));
    }

    public function testWholeBodiesAndBookingListsAreOmittedButCountsStay(): void
    {
        $booking = ['id' => 991, 'arrival' => '2026-12-24', 'firstName' => 'Maria', 'lastName' => 'Example', 'price' => 900];
        $clean = (new LogSanitizer())->context([
            'body' => json_encode([$booking]),
            'response_body' => [$booking],
            'request' => ['url' => 'https://api.pms.example'],
            'payload' => 'anything',
            'raw' => 'anything',
            'bookings' => [$booking],
            'booking' => $booking,
            'reservations' => [$booking],
            'bookings_count' => 1,
            'booking_id' => 991,
        ]);

        foreach (['body', 'response_body', 'request', 'payload', 'raw', 'bookings', 'booking', 'reservations'] as $key) {
            self::assertSame(LogSanitizer::OMITTED, $clean[$key], $key);
        }
        self::assertSame(1, $clean['bookings_count']);
        self::assertSame(991, $clean['booking_id']);
        self::assertSame(14, (new LogSanitizer())->context(['bookings' => 14])['bookings'], 'a count under the same key is kept');
        self::assertStringNotContainsString('Maria', (string) json_encode($clean));
    }

    public function testRegisteredSecretsAreMaskedWhereverTheyAppear(): void
    {
        $sanitizer = new LogSanitizer();
        $sanitizer->registerSecret('pk-live-3f9a1c77d2e04b55');
        $sanitizer->registerSecret('pk-live-3f9a1c77d2e04b55');
        $sanitizer->registerSecret('abc');

        self::assertSame('The key [redacted] was refused.', $sanitizer->message('The key pk-live-3f9a1c77d2e04b55 was refused.'));
        self::assertSame(
            ['detail' => 'sent [redacted] twice: [redacted]', 'list' => ['x[redacted]y']],
            $sanitizer->context(['detail' => 'sent pk-live-3f9a1c77d2e04b55 twice: pk-live-3f9a1c77d2e04b55', 'list' => ['xpk-live-3f9a1c77d2e04b55y']])
        );
        self::assertSame('abc stays: too short to be a secret', $sanitizer->message('abc stays: too short to be a secret'));
    }

    public function testCredentialsInsideTextAreMaskedEvenWhenNotRegistered(): void
    {
        $sanitizer = new LogSanitizer();

        self::assertSame(
            'GET https://api.pms.example/v2/bookings?token=[redacted]&arrivalFrom=2026-12-01&api_key=[redacted]',
            $sanitizer->message('GET https://api.pms.example/v2/bookings?token=abc123&arrivalFrom=2026-12-01&api_key=XYZ')
        );
        self::assertSame('header Authorization: Bearer [redacted] sent', $sanitizer->message('header Authorization: Bearer eyJhbGciOi.J9eyJzdWIi.OiIxMjM0 sent'));
        self::assertSame('refreshToken=[redacted]', $sanitizer->context(['detail' => 'refreshToken=abcdef'])['detail']);
        self::assertSame('x_token=[redacted]; client_secret=[redacted] key=[redacted]', $sanitizer->message('x_token=abc; client_secret=def key=ghi'));
        self::assertSame('lock_key=run:inn-1 monkey=1 nights=60', $sanitizer->message('lock_key=run:inn-1 monkey=1 nights=60'), 'ordinary assignments are left alone');
        self::assertSame('contact [omitted] about it', $sanitizer->message('contact maria.example+x@mail.example.com about it'));
    }

    public function testEntriesStaySmall(): void
    {
        $sanitizer = new LogSanitizer();

        $text = $sanitizer->context(['detail' => str_repeat('é', 2000)])['detail'];
        self::assertSame(503, mb_strlen($text));
        self::assertStringEndsWith('...', $text);

        $many = $sanitizer->context(array_fill(0, 80, 'x'));
        self::assertCount(51, $many);
        self::assertSame('30 more', $many['...']);

        $deep = $sanitizer->context(['a' => ['b' => ['c' => ['d' => ['e' => 'too deep']]]]]);
        self::assertSame(['a' => ['b' => ['c' => ['d' => LogSanitizer::OMITTED]]]], $deep);
    }

    public function testOnlyPlainValuesAreKept(): void
    {
        $clean = (new LogSanitizer())->context([
            'when' => new DateTimeImmutable('2026-11-11T12:00:00Z'),
            'mode' => PropertyMode::Shadow,
            'object' => new stdClass(),
            'nan' => NAN,
            'broken_text' => "caf\xE9",
        ]);

        self::assertSame('2026-11-11T12:00:00+00:00', $clean['when']);
        self::assertSame('shadow', $clean['mode']);
        self::assertSame('[stdClass]', $clean['object']);
        self::assertNull($clean['nan']);
        self::assertNotFalse(json_encode($clean), 'whatever comes in, the entry can be stored');
    }
}
