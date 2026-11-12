<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Infrastructure\Store\ArrayStore;

final class EmergencySwitchTest extends TestCase
{
    public function testTheOptionEngagesAndReleasesTheSwitch(): void
    {
        $store = new ArrayStore();
        $switch = new EmergencySwitch($store);
        self::assertFalse($switch->isEngaged());

        $switch->engage();
        self::assertTrue($switch->isEngaged());
        self::assertTrue($store->get(EmergencySwitch::KEY));

        $switch->release();
        self::assertFalse($switch->isEngaged());
        $store->set(EmergencySwitch::KEY, 'yes');
        self::assertFalse($switch->isEngaged(), 'only a stored true engages it');
    }

    public function testTheSwitchIsReadPastTheCacheOfTheRequest(): void
    {
        // A store like WordPress options: get() answers from what this request read before,
        // getFresh() reads what is stored now.
        $stored = new ArrayStore();
        $cached = new class ($stored) implements KeyValueStore {
            /** @var array<string, mixed> */
            private array $seen = [];

            public function __construct(private readonly ArrayStore $stored)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->seen) ? $this->seen[$key] : ($this->seen[$key] = $this->stored->get($key, $default));
            }

            public function getFresh(string $key, mixed $default = null): mixed
            {
                unset($this->seen[$key]);

                return $this->get($key, $default);
            }

            public function has(string $key): bool
            {
                return $this->stored->has($key);
            }

            public function set(string $key, mixed $value): void
            {
                $this->seen[$key] = $value;
                $this->stored->set($key, $value);
            }

            public function delete(string $key): void
            {
                unset($this->seen[$key]);
                $this->stored->delete($key);
            }
        };
        $switch = new EmergencySwitch($cached);
        self::assertFalse($switch->isEngaged());
        self::assertFalse($cached->get(EmergencySwitch::KEY, false), 'this request has the switch released in its cache');

        (new EmergencySwitch($stored))->engage();   // another request

        self::assertFalse($cached->get(EmergencySwitch::KEY, false), 'the cache alone would still say released');
        self::assertTrue($switch->isEngaged());
    }

    public function testTheConstantCannotBeReleasedFromTheSite(): void
    {
        $switch = new EmergencySwitch(new ArrayStore(), true);
        $switch->release();

        self::assertTrue($switch->isEngaged());
        self::assertTrue($switch->isEngagedByConstant());
    }

    #[RunInSeparateProcess]
    public function testTheConstantIsReadWhenDefinedAndTruthy(): void
    {
        self::assertFalse(EmergencySwitch::constantIsSet());
        define(EmergencySwitch::CONSTANT, true);
        self::assertTrue(EmergencySwitch::constantIsSet());
    }
}
