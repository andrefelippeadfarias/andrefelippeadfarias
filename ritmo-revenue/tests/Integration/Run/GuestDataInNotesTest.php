<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Mode\ModeRefused;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\ModesConfig;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * Rule 4 on every path (form and REST share the services): a decision note, a reason of a mode
 * change or of a pause never keeps guest data, and HTML is never kept as typed.
 */
final class GuestDataInNotesTest extends TestCase
{
    private const GUEST = 'Guest maria.silva@example.com +55 11 91234-5678 asked';

    public function testADecisionNoteWithGuestDataIsRefusedAndNothingIsDecided(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        self::assertNotSame([], $pending);
        $id = $pending[0]['id'];

        foreach (['reject', 'approve'] as $decision) {
            try {
                $decision === 'reject'
                    ? $world->stack->approvalDesk()->reject(RunWorld::PROPERTY, $id, RunWorld::OWNER, new ApprovalConfig(), self::GUEST)
                    : $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $id, RunWorld::OWNER, null, new ApprovalConfig(), self::GUEST);
                self::fail($decision . ' kept a note with guest data');
            } catch (ApprovalRefused $refused) {
                self::assertSame(ApprovalRefused::NOTE_PRIVATE, $refused->reason);
            }
        }
        self::assertSame('pending', $world->stack->approvalRequests()->find($id)['status']);
        self::assertStringNotContainsString('example.com', (string) json_encode($world->stack->events()->latest(RunWorld::PROPERTY)));
    }

    public function testAModeReasonWithGuestDataIsRefusedAndHtmlIsNeverKept(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        try {
            $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Shadow, RunWorld::OWNER, new ModesConfig(), '', 'Guest joana.souza@example.com called');
            self::fail('a reason with an email was kept');
        } catch (ModeRefused $refused) {
            self::assertSame(ModeRefused::REASON_PRIVATE, $refused->reason);
        }
        self::assertSame(PropertyMode::Approval, $world->stack->properties()->find(RunWorld::PROPERTY)?->mode);

        $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Shadow, RunWorld::OWNER, new ModesConfig(), '', 'stop <img src=x onerror=alert(1)> now');
        $events = json_encode($world->stack->events()->latest(RunWorld::PROPERTY, OperationEventRepository::MODE));
        self::assertStringNotContainsString('<img', (string) $events);
        self::assertStringContainsString('stop', (string) $events);
    }

    public function testAPauseReasonWithGuestDataIsRefused(): void
    {
        $world = new RunWorld(PropertyMode::Approval);

        $this->expectException(\InvalidArgumentException::class);
        $world->stack->pauses()->pause(RunWorld::PROPERTY, null, 'call ana.lima@example.com', RunWorld::OWNER);
    }
}
