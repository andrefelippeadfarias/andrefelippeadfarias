<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule {
    use Ritmo\Tests\Unit\Infrastructure\Schedule\WpScheduleFunctions;

    if (!function_exists(__NAMESPACE__ . '\wp_mail')) {
        /**
         * @param list<string> $to
         * @param list<string> $headers
         */
        function wp_mail(array $to, string $subject, string $body, array $headers = []): bool
        {
            WpScheduleFunctions::$mails[] = [$to, $subject, $body, $headers];

            return WpScheduleFunctions::$mailWorks;
        }

        /**
         * @param array<string, mixed> $args
         *
         * @return list<object>
         */
        function get_users(array $args): array
        {
            WpScheduleFunctions::$userQueries[] = $args;

            return WpScheduleFunctions::$users;
        }

        function get_user_meta(int $userId, string $key, bool $single): mixed
        {
            return WpScheduleFunctions::$meta[$userId][$key] ?? '';
        }

        function wp_next_scheduled(string $hook): int|false
        {
            return WpScheduleFunctions::$events[$hook][0] ?? false;
        }

        function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
        {
            WpScheduleFunctions::$events[$hook] = [$timestamp, $recurrence];

            return true;
        }

        function wp_clear_scheduled_hook(string $hook): int
        {
            unset(WpScheduleFunctions::$events[$hook]);

            return 1;
        }
    }
}

namespace Ritmo\Tests\Unit\Infrastructure\Schedule {
    use PHPUnit\Framework\TestCase;
    use Ritmo\Infrastructure\Schedule\WpCronTrigger;
    use Ritmo\Infrastructure\Schedule\WpMailer;
    use Ritmo\Infrastructure\Schedule\WpRecipientDirectory;
    use Ritmo\Infrastructure\Store\ArrayStore;

    /**
     * State of the WordPress functions stubbed for the adapters of the scheduler and the alerts.
     */
    final class WpScheduleFunctions
    {
        /** @var list<array{list<string>, string, string, list<string>}> */
        public static array $mails = [];

        public static bool $mailWorks = true;

        /** @var list<array<string, mixed>> */
        public static array $userQueries = [];

        /** @var list<object> */
        public static array $users = [];

        /** @var array<int, array<string, string>> */
        public static array $meta = [];

        /** @var array<string, array{int, string}> */
        public static array $events = [];

        public static function reset(): void
        {
            self::$mails = [];
            self::$mailWorks = true;
            self::$userQueries = [];
            self::$users = [];
            self::$meta = [];
            self::$events = [];
        }
    }

    final class WpAdaptersTest extends TestCase
    {
        protected function setUp(): void
        {
            WpScheduleFunctions::reset();
        }

        public function testTheMailerSendsPlainTextAndReportsFailures(): void
        {
            $mailer = new WpMailer();

            self::assertTrue($mailer->send(['a@example.org'], 'Subject', 'Body'));
            self::assertSame(['Content-Type: text/plain; charset=UTF-8'], WpScheduleFunctions::$mails[0][3]);
            WpScheduleFunctions::$mailWorks = false;
            self::assertFalse($mailer->send(['a@example.org'], 'Subject', 'Body'));
            self::assertFalse($mailer->send([], 'Subject', 'Body'), 'nobody to send to');
            self::assertCount(2, WpScheduleFunctions::$mails);
        }

        public function testRecipientsAreTheApproversWhoDidNotOptOutAndTheExtraAddresses(): void
        {
            WpScheduleFunctions::$users = [
                (object) ['ID' => 1, 'user_email' => 'Owner@Example.org'],
                (object) ['ID' => 2, 'user_email' => 'manager@example.org'],
                (object) ['ID' => 3, 'user_email' => 'not-an-address'],
            ];
            WpScheduleFunctions::$meta = [2 => [WpRecipientDirectory::OPT_OUT_META => 'no']];
            $settings = new ArrayStore();
            $settings->set(WpRecipientDirectory::EXTRA_KEY, ['desk@example.org', 'owner@example.org']);

            self::assertSame(['owner@example.org', 'desk@example.org'], (new WpRecipientDirectory($settings))->addresses());
            self::assertSame('ritmo_approve', WpScheduleFunctions::$userQueries[0]['capability']);
        }

        public function testTheTickEventIsScheduledOnceEveryFiveMinutes(): void
        {
            WpCronTrigger::schedule(1000);
            WpCronTrigger::schedule(2000);

            self::assertSame([1000, WpCronTrigger::INTERVAL], WpScheduleFunctions::$events[WpCronTrigger::HOOK]);
            self::assertSame(1000, WpCronTrigger::state()['next_tick']);
            self::assertSame(300, WpCronTrigger::withInterval([])[WpCronTrigger::INTERVAL]['interval']);
            WpCronTrigger::unschedule();
            self::assertNull(WpCronTrigger::state()['next_tick']);
        }
    }
}
