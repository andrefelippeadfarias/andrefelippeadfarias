<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Screens;

require_once __DIR__ . '/WpStubs.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Screens\AlertText;
use Ritmo\Admin\Screens\ConnectionText;
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

    public function testEveryFieldOfTheChangeLogHasAWord(): void
    {
        $fields = array_values((new ReflectionClass(ChangeField::class))->getConstants());
        foreach (array_filter($fields, 'is_string') as $field) {
            self::assertNotSame('other', Labels::field($field), $field);
        }
        self::assertSame('channel limit', Labels::field('pms_channel_limit:booking'));
        self::assertSame('listing base', Labels::field('base'));
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
        self::assertSame('Beds24 refused the key. Create a new key in Beds24 and paste it here.', ConnectionText::result('beds24', 'auth'));
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
