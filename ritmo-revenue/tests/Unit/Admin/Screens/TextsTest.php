<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Screens;

require_once __DIR__ . '/WpStubs.php';
require_once dirname(__DIR__) . '/Editors/stubs.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Screens\AlertsScreen;
use Ritmo\Admin\Screens\AlertText;
use Ritmo\Admin\Screens\ConnectionText;
use Ritmo\Admin\Screens\DiagnosticsView;
use Ritmo\Admin\Screens\LogText;
use Ritmo\Admin\Screens\NightText;
use Ritmo\Admin\Screens\UndoText;
use Ritmo\Admin\Screens\WarningText;
use Ritmo\Application\Approval\ApprovalStatus;
use Ritmo\Application\Dashboard\Connections\ConnectionReason;
use Ritmo\Application\Grid\Approvals\EntryRow;
use Ritmo\Application\Grid\Approvals\EntryWarnings;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertSeverity;
use Ritmo\Domain\Alerts\AlertStatus;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\Change\UndoSkip;

/**
 * Every value the screens show has a text of its own (admin spec 4.2, AC-ALR-1): no code reaches
 * a screen, and no fallback to "other" for a value the plugin knows.
 */
final class TextsTest extends TestCase
{
    public function testEveryEnumValueHasALabel(): void
    {
        foreach (PropertyMode::cases() as $mode) {
            self::assertNotSame('other', Labels::mode($mode->value), $mode->value);
            self::assertNotSame('other', Labels::modeSentence($mode->value), $mode->value);
        }
        foreach (PaceStatus::cases() as $pace) {
            self::assertNotSame('other', Labels::pace($pace->value), $pace->value);
        }
        foreach (AlertSeverity::cases() as $severity) {
            self::assertNotSame('other', Labels::severity($severity->value), $severity->value);
        }
        foreach (AlertStatus::cases() as $status) {
            self::assertNotSame('other', Labels::alertStatus($status->value), $status->value);
        }
        foreach (ApprovalStatus::cases() as $status) {
            self::assertNotSame('other', Labels::approvalStatus($status->value), $status->value);
        }
        foreach (['shadow', 'full', 'light', 'retry', 'manual', 'approved_write', 'undo'] as $kind) {
            self::assertNotSame('other', Labels::runKind($kind), $kind);
        }
        foreach (['collect', 'watch', 'measure', 'decide', 'validate', 'shadow', 'write', 'verify', 'report', ''] as $stage) {
            self::assertNotSame('other', Labels::runStage($stage), $stage);
        }
        self::assertSame('other', Labels::mode('a_later_mode'), 'an unknown value never shows its code');
    }

    /**
     * Regression (M4 product review): the run detail and the log show facts in words, never a raw
     * key, a user id, an ISO date or an HTTP route; those stay under "Technical details".
     */
    public function testDiagnosticsShowFactsInWordsAndKeepTheRestTechnical(): void
    {
        $facts = ['rooms' => 3, 'listings_missing' => [], 'bookings_read_since' => '2026-11-12T23:59:59+00:00', 'mode' => 'approval', 'actor' => 'user:1', 'stop' => null, 'operation' => 'POST /refresh_listing', 'http_status' => 405];

        $plain = DiagnosticsView::context($facts, 'UTC');
        self::assertStringContainsString('Rooms: 3', $plain);
        self::assertStringContainsString('Listings missing: 0', $plain);
        self::assertStringContainsString('Mode: ' . Labels::mode('approval'), $plain);
        self::assertDoesNotMatchRegularExpression('/user:1|T23:59|refresh_listing|405|_/', $plain);
        self::assertSame('operation: POST /refresh_listing · http_status: 405', DiagnosticsView::technical($facts));

        foreach (['A run ended.', 'PriceLabs write finished.', 'Beds24 request finished.'] as $message) {
            self::assertNotSame('', LogText::message($message));
            self::assertDoesNotMatchRegularExpression('/PriceLabs|Beds24/', LogText::message($message), 'the log names the systems by their role');
        }
    }

    /** Regression (M5 product review): every message the plugin logs has its own entry in LogText. */
    public function testEveryLoggedMessageIsTranslatedOnTheDiagnosticsScreen(): void
    {
        $root = dirname(__DIR__, 4) . '/src';
        $logText = (string) file_get_contents($root . '/Admin/Screens/LogText.php');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $missing = [];
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            preg_match_all("/(?:logger(?:\\(\\))?|log\\(\\))->(?:info|warning|error|debug)\\(\\s*'([^']+)'/", (string) file_get_contents((string) $file), $matches);
            preg_match_all("/(?:logger(?:\\(\\))?|log\\(\\))->log\\(\\s*[^,]+,\\s*'([^']+)'/", (string) file_get_contents((string) $file), $leveled);
            foreach ([...$matches[1], ...$leveled[1]] as $message) {
                if (!str_contains($logText, "'" . $message . "' =>")) {
                    $missing[] = $message;
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
        self::assertNotSame(Labels::provider('nobody'), Labels::provider('claude'));
        self::assertNotSame(Labels::provider('nobody'), Labels::provider('licence'));
        foreach (['none', 'stub', 'http'] as $licenceProvider) {
            self::assertNotSame(Labels::provider('nobody'), Labels::provider($licenceProvider), $licenceProvider);
        }
        self::assertSame('The licence was checked.', LogText::message('The licence was checked.'));
        self::assertSame('A run ended.', LogText::message('A run ended.'));
    }

    /** Regression (M4 product review): a failed write with no group left open does not say "Changes still open: 0". */
    public function testAnAlertDoesNotShowZeroGroupsOpen(): void
    {
        $facts = new \ReflectionMethod(AlertsScreen::class, 'facts');
        $shown = static fn (int $groups): array => array_column($facts->invoke(null, ['context' => ['provider' => 'pricing', 'groups_open' => $groups], 'room_code' => null, 'night' => null], null, 'UTC'), 'label');

        self::assertNotContains(AlertText::factLabel('groups_open'), $shown(0));
        self::assertContains(AlertText::factLabel('groups_open'), $shown(2));
    }

    public function testEveryFieldOfTheChangeLogHasAWord(): void
    {
        $fields = array_values((new ReflectionClass(ChangeField::class))->getConstants());
        foreach (array_filter($fields, 'is_string') as $field) {
            self::assertNotSame('other', Labels::field($field), $field);
        }
        self::assertSame('channel limit', Labels::field('pms_channel_limit:booking'));
        self::assertSame('listing base', Labels::field('base'));
    }

    public function testTheRefusalsOfTheRoomStepAreSaidInTheReadersLanguage(): void
    {
        $mapped = \Ritmo\Admin\Screens\RoomErrorText::of('The PMS room or the pricing listing is already mapped to the room "SR".');
        self::assertStringContainsString('room SR', $mapped);
        self::assertStringContainsString('Do not manage this room', $mapped);
        foreach (['A room type needs a name.', 'A room type needs at least one unit.', 'Room type "max" must not be lower than "min".', 'Room type "base" must be greater than zero.', 'Invalid room type code "a b".'] as $message) {
            self::assertNotSame($message, \Ritmo\Admin\Screens\RoomErrorText::of($message), $message);
        }
        self::assertSame('Something else.', \Ritmo\Admin\Screens\RoomErrorText::of('Something else.'));
        self::assertSame('Some rooms could not be saved: nothing was saved.', \Ritmo\Admin\Components\ErrorText::from(new \Ritmo\Application\Dashboard\Setup\RoomRowsRefused([['row' => 1, 'message' => 'x']])));
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Application/Setup/SetupService.php');
        self::assertStringContainsString("'The PMS room or the pricing listing is already mapped to the room \"%s\".'", $source, 'the sentence matched here is the one the application throws');
    }

    /** Regression (M5 final review): "Situação: completed" in the recent log of the Diagnostics screen. */
    public function testTheStatusOfARunInTheRecentLogIsInWords(): void
    {
        $value = new \ReflectionMethod(\Ritmo\Admin\Screens\DiagnosticsView::class, 'value');
        foreach (['completed', 'failed', 'running', 'stopped'] as $status) {
            self::assertSame(Labels::runStatus($status), $value->invoke(null, 'status', $status, 'UTC'), $status);
        }
    }

    /** Regression (M5 final review): the refusals of the setup steps and of a preset are said in the reader's language. */
    public function testTheRefusalsOfTheSetupAndOfAPresetAreSaidInTheReadersLanguage(): void
    {
        $root = dirname(__DIR__, 4) . '/src/Application';
        $unknown = [];
        foreach (['Setup/SetupService.php', 'Dashboard/Setup/SetupWizard.php', 'Setup/SampleCsv.php'] as $file) {
            preg_match_all("/throw new InvalidArgumentException\\((sprintf\\()?'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($root . '/' . $file), $matches, PREG_SET_ORDER);
            self::assertNotSame([], $matches, $file);
            foreach ($matches as $match) {
                $message = str_replace(['%s', '%d'], ['units', '3'], stripslashes($match[2]));
                if (\Ritmo\Admin\Components\OwnMessageText::of($message) === null) {
                    $unknown[] = $message;
                }
            }
        }
        self::assertSame([], $unknown, 'every refusal of the setup has its sentence in OwnMessageText');
        self::assertNull(\Ritmo\Admin\Components\OwnMessageText::of('Something else.'));
        self::assertSame('Line 7: the date must be written as YYYY-MM-DD.', \Ritmo\Admin\Components\OwnMessageText::of('Line 7: the date must be written as YYYY-MM-DD.'));
        self::assertStringContainsString('Choose another name', (string) \Ritmo\Admin\Components\OwnMessageText::of('A property with this id already exists.'));
        self::assertStringStartsWith('Something went wrong', \Ritmo\Admin\Components\ErrorText::from(new \InvalidArgumentException('A property with this id already exists.')), 'only a refusal thrown by the plugin is shown');

        $preset = new \Ritmo\Domain\Config\InvalidConfig('preset', [new \Ritmo\Domain\Config\ValidationError('rules.occupancy.goals.7', \Ritmo\Domain\Config\ValidationError::RANGE, 'must be at most 100')]);
        $text = \Ritmo\Admin\Components\ErrorText::from($preset);
        self::assertSame('These values are not accepted, so nothing was saved: rules.occupancy.goals.7: this is outside the allowed range.', $text);
        self::assertStringNotContainsString('Invalid preset', $text, 'never the English sentence of the application');
        $many = new \Ritmo\Domain\Config\InvalidConfig('preset', array_fill(0, 7, new \Ritmo\Domain\Config\ValidationError('', \Ritmo\Domain\Config\ValidationError::FORMAT, 'x')));
        self::assertStringEndsWith('(and 2 more problems)', \Ritmo\Admin\Components\ErrorText::from($many));
    }

    public function testEveryAlertCodeHasATitleAnExplanationAndAnAction(): void
    {
        $generic = [AlertText::title('a_later_code'), AlertText::explain('a_later_code'), AlertText::action('a_later_code')];
        foreach (AlertCode::cases() as $code) {
            self::assertNotSame($generic[0], AlertText::title($code->value), $code->value);
            self::assertNotSame($generic[1], AlertText::explain($code->value), $code->value);
            self::assertNotSame($generic[2], AlertText::action($code->value), $code->value);
        }
        self::assertSame(array_map(static fn (AlertCode $c): string => $c->value, AlertCode::cases()), AlertText::codes());
        foreach (AlertText::FACTS as $fact) {
            self::assertNotNull(AlertText::factLabel($fact), $fact);
        }
        self::assertNull(AlertText::factLabel('message'), 'a fact without a label is not shown');
        self::assertSame('The pricing system cannot be reached', AlertText::title('connector_down', ['provider' => 'pricelabs']));
    }

    public function testEveryConnectionReasonHasASentenceWithoutTechnicalDetail(): void
    {
        foreach (['beds24', 'pricelabs'] as $provider) {
            foreach (ConnectionReason::ALL as $reason) {
                $text = ConnectionText::result($provider, $reason, ['properties' => 2, 'listings' => 3, 'account_listings' => 4, 'pms' => 'beds24']);
                self::assertNotSame('', $text);
                self::assertDoesNotMatchRegularExpression('/HTTP|Exception|\{|\d{3} /', $text, $provider . ' ' . $reason);
            }
        }
        self::assertSame('Beds24 refused the token. Create a new token in Beds24 and paste it here.', ConnectionText::result('beds24', 'auth'), 'the PMS uses a token, the pricing system a key');
        self::assertSame('PriceLabs refused the key. Create a new key in PriceLabs and paste it here.', ConnectionText::result('pricelabs', 'auth'));
        self::assertSame('Connected to PriceLabs: 3 of 4 listings belong to your PMS.', ConnectionText::result('pricelabs', 'ok', ['listings' => 3, 'account_listings' => 4]));
    }

    public function testUndoReasonsWarningsAndRuleLabelsAreSentences(): void
    {
        $fallback = UndoText::reason('a_later_reason');
        foreach ([UndoSkip::CONFLICT, UndoSkip::ALREADY_UNDONE, UndoSkip::PAST_NIGHT, UndoSkip::NOTHING_TO_UNDO, UndoSkip::UNKNOWN_ROOM, UndoSkip::SEVERAL_CHANGES, UndoSkip::NOT_RESTORABLE] as $reason) {
            self::assertNotSame($fallback, UndoText::reason($reason), $reason);
        }
        $row = new EntryRow('item|A|2026-11-20', 'item', 'A', null, '2026-11-20', 'night|A|2026-11-20', [], null, EntryRow::DOWN, null, null, null, null, null, null, '', null, [], 'holiday:Example holiday', [], false);
        foreach (EntryWarnings::ALL as $warning) {
            self::assertNotSame('Note', WarningText::short($warning, $row), $warning);
            self::assertNotSame('', WarningText::long($warning, $row), $warning);
        }
        self::assertSame('Holiday: Example holiday', WarningText::short(EntryWarnings::HOLIDAY, $row));
        self::assertSame('Pace step', NightText::reason('PACE_STEP'));
        self::assertSame('another rule', NightText::reason('A_LATER_RULE'), 'a code without a sentence never reaches the screen');
    }
}
