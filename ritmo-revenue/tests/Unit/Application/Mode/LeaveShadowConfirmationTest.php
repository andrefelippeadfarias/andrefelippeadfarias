<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Mode;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Mode\LeaveShadowConfirmation;
use Ritmo\Application\Mode\ModeRefused;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;

/**
 * Leaving shadow mode asks for the property's id, typed, and the word that no other tool writes
 * prices for the property; the screen and the REST route both ask it.
 */
final class LeaveShadowConfirmationTest extends TestCase
{
    public function testLeavingShadowModeNeedsTheTypedIdAndTheSoleWriterTick(): void
    {
        $inn = new Property('inn', 'Inn', 'UTC', 'BRL', mode: PropertyMode::Shadow);
        foreach ([['', true], ['inn', false], ['other', true], [' inn ', false]] as [$typed, $sole]) {
            try {
                LeaveShadowConfirmation::check($inn, PropertyMode::Approval, $typed, $sole);
                self::fail('not confirmed: ' . json_encode([$typed, $sole]));
            } catch (ModeRefused $refused) {
                self::assertSame(ModeRefused::LEAVE_SHADOW, $refused->reason);
            }
        }
        LeaveShadowConfirmation::check($inn, PropertyMode::Approval, ' inn ', true);
        LeaveShadowConfirmation::check($inn, PropertyMode::Shadow, '', false);
        LeaveShadowConfirmation::check(new Property('inn', 'Inn', 'UTC', 'BRL', mode: PropertyMode::Approval), PropertyMode::Shadow, '', false);
        $this->addToAssertionCount(3);
    }

    public function testTheScreenAndTheRouteBothAskIt(): void
    {
        $root = dirname(__DIR__, 4);
        foreach (['src/Admin/Screens/OperationsActions.php', 'src/Rest/OperationRoutes.php'] as $file) {
            $code = (string) file_get_contents($root . '/' . $file);
            self::assertLessThan(strpos($code, '->modes()->change('), strpos($code, 'LeaveShadowConfirmation::check('), $file);
        }
        self::assertStringContainsString('name="sole_writer"', (string) file_get_contents($root . '/templates/admin/screens/operations.php'));
    }
}
