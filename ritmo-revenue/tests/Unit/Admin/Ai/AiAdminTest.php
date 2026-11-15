<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Ai;

require_once __DIR__ . '/stubs.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Admin\Ai\AiWords;
use Ritmo\Domain\Ai\AiEffort;
use Ritmo\Domain\Ai\AiModel;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Ai\DraftReader;
use Ritmo\Tests\Support\Fixtures;

/**
 * The assistant's screens in words and their safety on the source: every stop and every refusal
 * of a draft has its own sentence; the handlers that ask the assistant check ritmo_use_ai first;
 * every route checks a capability and the nonce; the key is write-only; the screens never show a
 * prompt or JSON.
 */
final class AiAdminTest extends TestCase
{
    public function testEveryStopHasItsOwnSentence(): void
    {
        $reasons = (new \ReflectionClass(AiStopped::class))->getConstants();
        $default = AiWords::reason('something else');
        $seen = [];
        foreach ($reasons as $reason) {
            if (!is_string($reason) || $reason === AiStopped::PROVIDER) {
                continue;
            }
            $text = AiWords::reason($reason);
            self::assertNotSame($default, $text, $reason);
            self::assertStringNotContainsString('[wrong text domain]', $text);
            $seen[] = $text;
        }
        self::assertSame(count($seen), count(array_unique($seen)));
    }

    public function testEveryRefusalOfADraftHasItsOwnSentence(): void
    {
        $default = AiWords::problem('unknown');
        foreach (array_keys(DraftReader::PROBLEMS) as $code) {
            self::assertNotSame($default, AiWords::problem($code . ':STD'), $code);
        }
        self::assertSame('LOFT is not a room of this property.', AiWords::problem('unknown_room:LOFT'));
    }

    public function testAStopGivesItsDetailsAsSentences(): void
    {
        $refused = AiWords::stopped(new AiStopped(AiStopped::DRAFT_REFUSED, 'x', ['problems' => ['amount_not_written', 'setting_denied:safety.never_raise_today']]));
        self::assertSame(['The amount is not one you wrote.', 'safety.never_raise_today is not a setting the assistant may change (safety limits, modes, writing and quotas never are).'], $refused['details']);
        self::assertSame(['Numbers not in the data: 5, 1.300'], AiWords::stopped(new AiStopped(AiStopped::UNKNOWN_NUMBER, 'x', ['numbers' => ['5', '1.300']]))['details']);
        self::assertStringContainsString('cannot turn this request into a draft: Mode changes are not drafts.', AiWords::stopped(new AiStopped(AiStopped::NOT_SUPPORTED, 'x', ['summary' => 'Mode changes are not drafts.', 'questions' => []]))['message']);
    }

    public function testModelsEffortsAndPurposesAreNamed(): void
    {
        foreach (AiModel::CHOICES as $model) {
            self::assertStringStartsWith('Claude ', AiWords::model($model));
        }
        foreach (AiEffort::cases() as $effort) {
            self::assertNotSame('', AiWords::effort($effort));
        }
        foreach (AiPurpose::cases() as $purpose) {
            self::assertNotSame('Other', AiWords::purpose($purpose->value));
        }
        self::assertSame('US$ 1,234.50', AiWords::dollars(1234.5));
    }

    public function testTheHandlersThatAskTheAssistantCheckItsCapabilityFirst(): void
    {
        $source = (string) file_get_contents(Fixtures::pluginRoot() . '/src/Admin/Ai/AiActions.php');
        foreach (['draft', 'weekly', 'open', 'discard'] as $handler) {
            self::assertMatchesRegularExpression('/public static function ' . $handler . '\(Input \$form\): array\s*\{\s*self::requireUse\(\);/', $source, $handler);
        }
        self::assertMatchesRegularExpression('/new FormAction\(self::SETTINGS, Capability::MANAGE_CONNECTIONS/', $source);
        self::assertMatchesRegularExpression('/new FormAction\(self::OPEN, Capability::MANAGE_RULES, \$page, self::open\(\.\.\.\), true, \'draft\'\)/', $source, 'opening a draft needs the rules capability and a nonce per draft');
    }

    public function testEveryRouteChecksACapabilityAndTheNonce(): void
    {
        $source = (string) file_get_contents(Fixtures::pluginRoot() . '/src/Rest/Ai/AiRoutes.php');
        self::assertSame(substr_count($source, 'register_rest_route('), substr_count($source, "'permission_callback' => \$use") + substr_count($source, "'permission_callback' => RestRoutes::allowed(Roles::MANAGE_CONNECTIONS)"));
        self::assertStringContainsString('$use = RestRoutes::allowed(AiCapability::USE_AI);', $source);
    }

    public function testTheKeyIsWriteOnlyAndNoPromptOrJsonReachesAScreen(): void
    {
        $settings = (string) file_get_contents(Fixtures::pluginRoot() . '/templates/admin/ai/settings.php');
        self::assertMatchesRegularExpression('/<input type="password" id="ritmo-ai-key" name="api_key"[^>]*autocomplete="new-password"/', $settings);
        self::assertDoesNotMatchRegularExpression('/name="api_key"[^>]*value=/', $settings, 'the key is never written back into the form');
        foreach (glob(Fixtures::pluginRoot() . '/templates/admin/ai/*.php') ?: [] as $template) {
            $code = (string) file_get_contents($template);
            self::assertStringNotContainsString('json_encode', $code, basename($template));
            self::assertStringNotContainsString('PromptLibrary', $code, basename($template));
            self::assertStringNotContainsString('reveal(', $code, basename($template));
        }
        foreach (glob(Fixtures::pluginRoot() . '/src/Admin/Ai/*.php') ?: [] as $file) {
            self::assertStringNotContainsString('->key()', (string) file_get_contents($file), basename($file) . ' never reads the key in clear');
        }
    }
}
