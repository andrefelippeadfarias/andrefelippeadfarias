<?php

declare(strict_types=1);

namespace Ritmo\Admin {
    if (!function_exists(__NAMESPACE__ . '\__')) {
        /**
         * Stand-in for the WordPress translation function: the English text as it is.
         */
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
}

namespace Ritmo\Tests\Unit\WordPress {
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use Ritmo\Admin\RefusalText;
    use Ritmo\Application\Approval\ApprovalRefused;
    use Ritmo\Application\Mode\ModePreconditions;
    use Ritmo\Application\Mode\ModeRefused;

    /**
     * The refusals of a change of mode or of a decision on a plan reach the screens through the
     * text domain, condition by condition (review finding of the M3 correction phase: they were
     * shown as the use cases' English sentences).
     */
    final class RefusalTextTest extends TestCase
    {
        public function testEveryModeRefusalAndConditionHasItsTranslatableSentence(): void
        {
            foreach ([ModeRefused::NO_CAPABILITY, ModeRefused::DISABLED, ModeRefused::CONFIRMATION, ModeRefused::CONDITIONS] as $reason) {
                $text = RefusalText::mode(new ModeRefused($reason, 'english'));
                self::assertStringNotContainsString('[wrong text domain]', $text);
                self::assertNotSame('english', $text);
            }
            $refused = new ModeRefused(ModeRefused::CONDITIONS, 'english', [
                ModePreconditions::unmet('connection_untested', ['provider' => 'pricelabs'], 'english'),
                ModePreconditions::unmet('shadow_runs', ['needed' => 3, 'done' => 1], 'english'),
                ModePreconditions::unmet('approval_time', ['days_needed' => 7, 'plans_needed' => 5, 'days' => 2, 'plans' => 1], 'english'),
            ]);
            self::assertSame(
                'Test the pricelabs connection first: it must have passed within 24 hours. The last 3 runs must have completed in shadow mode with a plan (1 did). The property needs 7 days in approval and 5 approved plans written first (2 days and 1 plans so far).',
                RefusalText::mode($refused),
            );
        }

        public function testEveryConditionCodeOfTheUseCasesIsKnown(): void
        {
            $source = (string) file_get_contents((string) (new ReflectionClass(ModePreconditions::class))->getFileName())
                . (string) file_get_contents((string) (new ReflectionClass(\Ritmo\Application\Mode\ModeService::class))->getFileName());
            preg_match_all("/unmet\\('([a-z_]+)'/", $source, $codes);
            self::assertNotSame([], $codes[1]);
            foreach (array_unique($codes[1]) as $code) {
                $text = RefusalText::mode(new ModeRefused(ModeRefused::CONDITIONS, 'english', [ModePreconditions::unmet($code, [], 'english')]));
                self::assertNotSame('A condition of this mode is not met.', $text, $code . ' has no sentence');
            }
        }

        public function testEveryApprovalRefusalHasItsTranslatableSentence(): void
        {
            foreach ((new ReflectionClass(ApprovalRefused::class))->getConstants() as $reason) {
                $text = RefusalText::approval(new ApprovalRefused((string) $reason, 'english'));
                self::assertStringNotContainsString('[wrong text domain]', $text);
                self::assertNotSame('english', $text, (string) $reason);
            }
        }
    }
}
