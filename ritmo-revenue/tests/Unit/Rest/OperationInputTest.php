<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Rest;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Rest\OperationInput;

/**
 * The inputs that decide what may be written are read strictly: a refusal typed wrong never
 * approves a plan, and a request without a clear position never releases the emergency switch.
 */
final class OperationInputTest extends TestCase
{
    public function testOnlyTheTwoDecisionsAreAccepted(): void
    {
        self::assertSame('approve', OperationInput::decision('approve'));
        self::assertSame('reject', OperationInput::decision('reject'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badDecisions(): iterable
    {
        yield 'refuse' => ['refuse'];
        yield 'rejected' => ['rejected'];
        yield 'deny' => ['deny'];
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'upper case' => ['APPROVE'];
        yield 'list' => [['approve']];
    }

    #[DataProvider('badDecisions')]
    public function testAnyOtherDecisionIsRefused(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationInput::decision($value);
    }

    public function testKeysAreAbsentOrANonEmptyListOfKeys(): void
    {
        self::assertNull(OperationInput::keys(null));
        self::assertSame(['STD|2027-03-05', 'GDN|2027-03-06'], OperationInput::keys(['STD|2027-03-05', ' GDN|2027-03-06 ']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badKeys(): iterable
    {
        yield 'one key as text' => ['STD|2027-03-05'];
        yield 'empty list' => [[]];
        yield 'empty key' => [['']];
        yield 'number' => [[3]];
        yield 'map' => [['a' => 'STD|2027-03-05']];
        yield 'false' => [false];
    }

    #[DataProvider('badKeys')]
    public function testMalformedKeysAreRefusedInsteadOfApprovingEverything(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationInput::keys($value);
    }

    public function testTheSwitchPositionIsReadStrictly(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE'] as $engage) {
            self::assertTrue(OperationInput::engaged($engage), var_export($engage, true));
        }
        foreach ([false, 0, '0', 'false'] as $release) {
            self::assertFalse(OperationInput::engaged($release), var_export($release, true));
        }
        self::assertTrue(OperationInput::switchPosition('engage'));
        self::assertFalse(OperationInput::switchPosition('release'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unclearPositions(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'misspelt' => ['engage'];
        yield 'yes' => ['yes'];
        yield 'two' => [2];
        yield 'list' => [[true]];
    }

    #[DataProvider('unclearPositions')]
    public function testAnUnclearPositionNeverReleasesTheSwitch(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationInput::engaged($value);
    }

    public function testTheFormAcceptsOnlyEngageOrRelease(): void
    {
        foreach (['', 'engaged', 'Engage', 'off'] as $value) {
            try {
                OperationInput::switchPosition($value);
                self::fail('accepted "' . $value . '"');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
