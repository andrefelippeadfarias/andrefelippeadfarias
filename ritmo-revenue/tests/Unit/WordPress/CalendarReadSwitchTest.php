<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\WordPress\ConnectorFactory;

/**
 * The pricing system's calendar read (POST /listing_prices, a read) is on by default since the
 * decision of 12 Nov; only RITMO_PRICING_CALENDAR_READ defined as false switches it off. Each
 * case runs in its own PHP process, because a constant cannot be undefined.
 */
final class CalendarReadSwitchTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function definitions(): array
    {
        return [
            'not defined: on' => ['', true],
            'defined true: on' => ["define('RITMO_PRICING_CALENDAR_READ', true);", true],
            'defined false: off' => ["define('RITMO_PRICING_CALENDAR_READ', false);", false],
            'defined as text: on' => ["define('RITMO_PRICING_CALENDAR_READ', '0');", true],
        ];
    }

    #[DataProvider('definitions')]
    public function testTheConstantOnlySwitchesTheReadOff(string $definition, bool $expected): void
    {
        $autoload = var_export(Fixtures::pluginRoot() . '/src/autoload.php', true);
        $code = sprintf(
            '%s require %s; $factory = (new ReflectionClass(%s::class))->newInstanceWithoutConstructor(); echo $factory->readsPricingCalendar() ? "on" : "off";',
            $definition,
            $autoload,
            ConnectorFactory::class
        );
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame($expected ? 'on' : 'off', implode('', $output));
        self::assertSame('RITMO_PRICING_CALENDAR_READ', ConnectorFactory::CALENDAR_READ_CONSTANT);
    }
}
