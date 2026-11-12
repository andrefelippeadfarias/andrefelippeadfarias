<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Store {
    use Ritmo\Tests\Unit\Infrastructure\Support\WpFunctions;

    if (!function_exists(__NAMESPACE__ . '\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return array_key_exists($name, WpFunctions::$options) ? WpFunctions::$options[$name] : $default;
        }

        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            WpFunctions::$optionWrites[] = [$name, $value, $autoload];
            WpFunctions::$options[$name] = $value;

            return true;
        }

        function delete_option(string $name): bool
        {
            unset(WpFunctions::$options[$name]);

            return true;
        }
    }
}

namespace Ritmo\Tests\Unit\Infrastructure\Store {
    use InvalidArgumentException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Ritmo\Contracts\KeyValueStore;
    use Ritmo\Infrastructure\Store\ArrayStore;
    use Ritmo\Infrastructure\Store\OptionsStore;
    use Ritmo\Tests\Unit\Infrastructure\Support\WpFunctions;
    use stdClass;

    final class StoresTest extends TestCase
    {
        protected function setUp(): void
        {
            WpFunctions::reset();
        }

        protected function tearDown(): void
        {
            WpFunctions::reset();
        }

        /**
         * @return array<string, array{KeyValueStore}>
         */
        public static function stores(): array
        {
            return ['ArrayStore' => [new ArrayStore()], 'OptionsStore' => [new OptionsStore()]];
        }

        #[DataProvider('stores')]
        public function testAValueComesBackWithTheTypeThatWentIn(KeyValueStore $store): void
        {
            $values = [
                'int' => 4, 'zero' => 0, 'float' => 60.0, 'fraction' => 0.1, 'true' => true, 'false' => false, 'null' => null,
                'text' => 'São João', 'empty' => '', 'numeric_text' => '007',
                'list' => [1, 'two', 3.5, null, false],
                'map' => ['seed' => null, 'entries' => ['a.b' => ['k' => 'x', 'n' => 7]], 'empty' => []],
            ];

            foreach ($values as $key => $value) {
                $store->set($key, $value);
            }

            foreach ($values as $key => $value) {
                self::assertTrue($store->has($key), $key);
                self::assertSame($value, $store->get($key, 'default'), $key);
            }
        }

        #[DataProvider('stores')]
        public function testAMissingKeyGivesTheDefault(KeyValueStore $store): void
        {
            self::assertFalse($store->has('missing'));
            self::assertNull($store->get('missing'));
            self::assertSame(['fallback'], $store->get('missing', ['fallback']));
        }

        #[DataProvider('stores')]
        public function testAStoredNullOrFalseIsNotAMissingKey(KeyValueStore $store): void
        {
            $store->set('nothing', null);
            $store->set('off', false);

            self::assertTrue($store->has('nothing'));
            self::assertNull($store->get('nothing', 'default'));
            self::assertFalse($store->get('off', 'default'));
        }

        #[DataProvider('stores')]
        public function testAKeyIsReplacedAndDeleted(KeyValueStore $store): void
        {
            $store->set('db_version', 3);
            $store->set('db_version', 4);
            self::assertSame(4, $store->get('db_version'));

            $store->delete('db_version');
            $store->delete('never-there');

            self::assertFalse($store->has('db_version'));
            self::assertSame(0, $store->get('db_version', 0));
        }

        #[DataProvider('stores')]
        public function testWhatCannotBeStoredIsRefused(KeyValueStore $store): void
        {
            foreach ([new stdClass(), ['nested' => new stdClass()], NAN, "\xB1\x31"] as $value) {
                try {
                    $store->set('bad', $value);
                    self::fail('The value must be refused.');
                } catch (InvalidArgumentException) {
                }
            }
            self::assertFalse($store->has('bad'));
        }

        public function testTheArrayStoreStartsWithValidContentAndShowsIt(): void
        {
            $store = new ArrayStore(['a' => 1, 'b' => ['c' => true]]);

            self::assertSame(['a' => 1, 'b' => ['c' => true]], $store->all());

            try {
                $store->set('', 1);
                self::fail('An empty key must be refused.');
            } catch (InvalidArgumentException) {
            }
            $this->expectException(InvalidArgumentException::class);
            new ArrayStore(['bad' => new stdClass()]);
        }

        public function testOptionsArePrefixedStoredAsJsonAndNotAutoloaded(): void
        {
            $store = new OptionsStore();
            $store->set('db_version', 4);
            $store->set('vault', ['seed' => null, 'entries' => []]);

            self::assertSame(
                [['ritmo_db_version', '4', false], ['ritmo_vault', '{"seed":null,"entries":[]}', false]],
                WpFunctions::$optionWrites
            );

            (new OptionsStore('other_'))->set('db_version', 1);
            self::assertSame(4, $store->get('db_version'));
            self::assertSame('1', WpFunctions::$options['other_db_version']);
        }

        public function testAnOptionWrittenBySomethingElseIsNeverUnserializedOrTrusted(): void
        {
            $store = new OptionsStore();
            WpFunctions::$options['ritmo_legacy'] = ['an' => 'array'];
            WpFunctions::$options['ritmo_broken'] = '{not json';
            WpFunctions::$options['ritmo_object'] = new stdClass();

            self::assertSame('default', $store->get('legacy', 'default'));
            self::assertSame('default', $store->get('broken', 'default'));
            self::assertSame('default', $store->get('object', 'default'));
            self::assertTrue($store->has('legacy'), 'the option exists, even if unreadable');
        }

        public function testOptionKeysAreChecked(): void
        {
            $store = new OptionsStore();

            foreach (['', 'has space', 'semi;colon', str_repeat('k', 121), 'ação'] as $key) {
                try {
                    $store->set($key, 1);
                    self::fail("\"$key\" must be refused.");
                } catch (InvalidArgumentException) {
                }
            }
            self::assertSame([], WpFunctions::$optionWrites);
        }
    }
}
