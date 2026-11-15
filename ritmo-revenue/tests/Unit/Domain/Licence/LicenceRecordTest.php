<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Licence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Licence\CheckDue;
use Ritmo\Domain\Licence\GracePolicy;
use Ritmo\Domain\Licence\LicenceKey;
use Ritmo\Domain\Licence\LicenceRecord;

final class LicenceRecordTest extends TestCase
{
    private static function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    public function testAnUnreachableAnswerKeepsTheLastRealAnswer(): void
    {
        $valid = LicenceRecord::forKey(LicenceKey::parse('RR-KEY-00000001'))->withAnswer('http', LicenceRecord::VALID, self::at('2027-01-01T00:00:00Z'), self::at('2026-11-01T10:00:00Z'), 'Licence active.');
        $down = $valid->withAnswer('http', LicenceRecord::UNREACHABLE, null, self::at('2026-11-02T10:00:00Z'), 'Down');

        self::assertSame(LicenceRecord::VALID, $down->status);
        self::assertEquals($valid->expiresAt, $down->expiresAt);
        self::assertEquals(self::at('2026-11-01T10:00:00Z'), $down->lastAnswerAt);
        self::assertEquals(self::at('2026-11-01T10:00:00Z'), $down->lastValidAt);
        self::assertEquals(self::at('2026-11-02T10:00:00Z'), $down->lastAttemptAt);
        self::assertSame(LicenceRecord::UNREACHABLE, $down->lastAttemptStatus);
        self::assertSame('Licence active.', $down->message);
    }

    public function testAnAnswerOlderThanTheLastAttemptIsIgnored(): void
    {
        $record = LicenceRecord::forKey(LicenceKey::parse('RR-KEY-00000001'))->withAnswer('http', LicenceRecord::INVALID, null, self::at('2026-11-02T10:00:00Z'));

        self::assertSame($record, $record->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-01T10:00:00Z')));
    }

    public function testAnExpiredAnswerWithoutADateKeepsTheKnownEndDate(): void
    {
        $record = LicenceRecord::forKey(LicenceKey::parse('RR-KEY-00000001'))
            ->withAnswer('http', LicenceRecord::VALID, self::at('2026-11-20T00:00:00Z'), self::at('2026-11-15T10:00:00Z'))
            ->withAnswer('http', LicenceRecord::EXPIRED, null, self::at('2026-11-21T10:00:00Z'));

        self::assertEquals(self::at('2026-11-20T00:00:00Z'), $record->expiresAt);
        self::assertEquals(self::at('2026-11-21T10:00:00Z'), $record->problemSince);
    }

    public function testTheRecordSurvivesItsStoredForm(): void
    {
        $record = LicenceRecord::forKey(LicenceKey::parse('RR-KEY-00000001'))
            ->withAnswer('http', LicenceRecord::VALID, self::at('2027-01-01T00:00:00+02:00'), self::at('2026-11-01T10:00:00Z'), 'ok')
            ->withAnswer('http', LicenceRecord::EXPIRED, null, self::at('2026-11-03T10:00:00Z'), "<b>Ended</b>\nby\tthe shop");

        $stored = $record->toArray();
        self::assertSame('2026-12-31T22:00:00Z', $stored['expires_at'], 'instants are kept in UTC');
        self::assertSame('Ended by the shop', $stored['message'], 'markup and control characters are removed');
        self::assertEquals($record, LicenceRecord::fromArray(json_decode((string) json_encode($stored), true)));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function damaged(): array
    {
        return [
            'not an array' => ['valid'],
            'unknown status' => [['key' => 'abcdef0123456789', 'status' => 'gold']],
            'bad fingerprint' => [['key' => 'the-key-itself']],
            'bad instant' => [['key' => 'abcdef0123456789', 'status' => 'valid', 'last_valid_at' => 'now']],
            'impossible date' => [['key' => 'abcdef0123456789', 'status' => 'valid', 'last_valid_at' => '2026-02-31T10:00:00Z']],
            'number for text' => [['key' => 12]],
        ];
    }

    #[DataProvider('damaged')]
    public function testADamagedRecordIsTheEmptyOneAndGrantsNothing(mixed $stored): void
    {
        $record = LicenceRecord::fromArray($stored);

        self::assertFalse($record->hasKey());
        self::assertNull($record->lastValidAt);
    }

    public function testTheRecordNeverHoldsTheKey(): void
    {
        $key = LicenceKey::parse('RR-SECRET-KEY-9999');
        $record = LicenceRecord::forKey($key)->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-01T10:00:00Z'));

        self::assertStringNotContainsString('RR-SECRET-KEY-9999', (string) json_encode($record->toArray()));
        self::assertTrue($record->isFor($key));
        self::assertFalse($record->isFor(LicenceKey::parse('RR-OTHER-KEY-0001')));
    }

    public function testUnknownStatusesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LicenceRecord())->withAnswer('http', 'gold', null, self::at('2026-11-01T10:00:00Z'));
    }

    public function testKeysAreCheckedAndNeverShownByDebugOutput(): void
    {
        $key = LicenceKey::parse('  RR-2026_ab.cd:EF  ');
        self::assertSame('RR-2026_ab.cd:EF', $key->value());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key->fingerprint());
        self::assertStringNotContainsString('RR-2026', print_r($key, true));
        self::assertFalse($key->isTestKey());
        self::assertTrue(LicenceKey::parse('TEST-VALID-1')->isTestKey());

        foreach (['short', 'has space inside', str_repeat('a', 129), 'key<script>', '-leading-hyphen'] as $bad) {
            try {
                LicenceKey::parse($bad);
                self::fail('accepted ' . $bad);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAKeyIsNeverSerialised(): void
    {
        $this->expectException(LogicException::class);
        serialize(LicenceKey::parse('RR-SECRET-KEY-9999'));
    }

    public function testTheGracePolicyNeverAcceptsAnUnreasonableValue(): void
    {
        self::assertSame(30, GracePolicy::fromSetting(null)->days);
        self::assertSame(14, GracePolicy::fromSetting(14)->days);
        self::assertSame(14, GracePolicy::fromSetting('14')->days);
        self::assertSame(30, GracePolicy::fromSetting(0)->days);
        self::assertSame(30, GracePolicy::fromSetting(9999)->days);
        self::assertSame(30, GracePolicy::fromSetting('forever')->days);
        self::assertSame(30, GracePolicy::fromSetting(7.5)->days);
        $this->expectException(InvalidArgumentException::class);
        new GracePolicy(91);
    }

    public function testTheScheduledCheckIsDueOnceADayAndSoonerAfterAnOutage(): void
    {
        $record = LicenceRecord::forKey(LicenceKey::parse('RR-KEY-00000001'));
        self::assertFalse(CheckDue::isDue(new LicenceRecord(), self::at('2026-11-15T10:00:00Z')), 'no key, no check');
        self::assertTrue(CheckDue::isDue($record, self::at('2026-11-15T10:00:00Z')), 'a key never checked');

        $answered = $record->withAnswer('http', LicenceRecord::VALID, null, self::at('2026-11-15T10:00:00Z'));
        self::assertFalse(CheckDue::isDue($answered, self::at('2026-11-16T05:59:59Z')));
        self::assertTrue(CheckDue::isDue($answered, self::at('2026-11-16T06:00:00Z')));

        $down = $answered->withAnswer('http', LicenceRecord::UNREACHABLE, null, self::at('2026-11-16T06:00:00Z'));
        self::assertFalse(CheckDue::isDue($down, self::at('2026-11-16T11:59:59Z')));
        self::assertTrue(CheckDue::isDue($down, self::at('2026-11-16T12:00:00Z')));
        self::assertTrue(CheckDue::isDue($down, self::at('2026-11-16T05:00:00Z')), 'the clock moved back');
    }
}
