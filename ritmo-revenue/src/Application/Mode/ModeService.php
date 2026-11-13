<?php

declare(strict_types=1);

namespace Ritmo\Application\Mode;

use OutOfBoundsException;
use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Rules\PrivacyCheck;
use Ritmo\Application\Write\Gate\AutonomousAuthorization;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\ModesConfig;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;

/**
 * The operating mode of each property (operations spec 7, O35, O36, O43):
 *
 * - shadow -> approval: a person who manages the connections, when the conditions of
 *   ModePreconditions::toApproval() are met (the configured shadow runs, the newest within 24 h,
 *   connections tested, no open failure alert, the emergency switch released);
 * - approval -> autonomous: only when the site allows the autonomous mode, by a person who manages
 *   the connections, typing the property's id as confirmation, after the configured days in
 *   approval and approved plans written, with no write failed in 7 days and no wave holding cuts;
 *   shadow -> autonomous is never possible;
 * - any lower mode: anyone who approves, manages the rules or the connections, at once (a write in
 *   progress stops before its next slice).
 *
 * Every change is recorded with who, when and why; any change ends the plans waiting for approval
 * and the approvals not written yet (an approval never outlives the mode it was given in).
 * The only changes the plugin makes on its own lower the mode: autonomous -> approval after the
 * configured runs in a row whose writes failed.
 */
final class ModeService
{
    public const MANAGE_RULES = 'ritmo_manage_rules';
    public const MANAGE_CONNECTIONS = 'ritmo_manage_connections';

    public const REASON_MAX = 190;

    private const FAILURES_KEY = 'write_failures_';

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly OperationEventRepository $events,
        private readonly ApprovalService $approvals,
        private readonly CapabilityCheck $capabilities,
        private readonly AlertService $alerts,
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
        private readonly ModePreconditions $preconditions,
    ) {
    }

    /**
     * @param string $confirmation for the autonomous mode: the property's id, typed by the person
     * @param string $reason       kept in the log of operations: plain text, never guest data
     *
     * @throws OutOfBoundsException when the property does not exist
     * @throws ModeRefused          when the change is not allowed now
     */
    public function change(string $propertyId, PropertyMode $to, int $userId, ModesConfig $config, string $confirmation = '', string $reason = ''): Property
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $from = $property->mode;
        $reason = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($reason)));
        if (PrivacyCheck::problem($reason, self::REASON_MAX) !== null) {
            throw new ModeRefused(ModeRefused::REASON_PRIVATE, 'A reason cannot hold an email, a phone number or a document, and has at most 190 characters.');
        }
        if ($from === $to) {
            return $property;
        }
        $this->check($property, $to, $userId, $config, $confirmation);
        $this->apply($property, $to, 'user:' . $userId, $reason);

        return $this->properties->find($propertyId) ?? $property;
    }

    /**
     * The authorization of an autonomous property's writes: who switched it on and when.
     */
    public function autonomousAuthorization(Property $property): ?AutonomousAuthorization
    {
        if ($property->mode !== PropertyMode::Auto) {
            return null;
        }
        $event = $this->events->lastTo($property->id, OperationEventRepository::MODE, PropertyMode::Auto->value);
        $user = $event === null ? 0 : (int) substr($event['actor'], strlen('user:'));

        return $event === null || $user <= 0 ? null : new AutonomousAuthorization($property->id, $user, $event['at']);
    }

    /**
     * After the write of an autonomous run: counts the runs in a row whose writes failed and
     * lowers the property to approval when they reach the configured number.
     *
     * @return bool true when the property was lowered
     */
    public function afterWrite(Property $property, bool $failed, ModesConfig $config): bool
    {
        $key = self::FAILURES_KEY . $property->id;
        if (!$failed) {
            $this->store->delete($key);

            return false;
        }
        $count = (int) $this->store->get($key, 0) + 1;
        $this->store->set($key, $count);
        if ($property->mode !== PropertyMode::Auto || $count < $config->demoteAfterFailedWrites) {
            return false;
        }
        $this->store->delete($key);
        $reason = sprintf('%d runs in a row could not write: back to approval.', $count);
        $this->apply($property, PropertyMode::Approval, 'system', $reason);
        $this->alerts->raise(new AlertDraft(AlertCode::ModeDemoted, $property->id, [PropertyMode::Approval->value], $reason, ['failed_runs' => $count]));

        return true;
    }

    /**
     * @throws ModeRefused
     */
    private function check(Property $property, PropertyMode $to, int $userId, ModesConfig $config, string $confirmation): void
    {
        $lower = self::rank($to) < self::rank($property->mode);
        if ($lower) {
            if (!$this->any($userId, [CapabilityCheck::APPROVE, self::MANAGE_RULES, self::MANAGE_CONNECTIONS])) {
                throw new ModeRefused(ModeRefused::NO_CAPABILITY, 'Lowering the mode needs the approval, rules or connections capability.');
            }

            return;
        }
        if ($to === PropertyMode::Approval) {
            if (!$this->capabilities->userCan($userId, self::MANAGE_CONNECTIONS)) {
                throw new ModeRefused(ModeRefused::NO_CAPABILITY, 'Switching to approval needs the connections capability.');
            }
            self::refuseUnmet($this->preconditions->toApproval($property, $config));

            return;
        }
        $this->checkAutonomous($property, $userId, $config, $confirmation);
    }

    /**
     * @throws ModeRefused
     */
    private function checkAutonomous(Property $property, int $userId, ModesConfig $config, string $confirmation): void
    {
        if (!$config->autonomousEnabled) {
            throw new ModeRefused(ModeRefused::DISABLED, 'The autonomous mode is switched off for this site.');
        }
        if ($property->mode !== PropertyMode::Approval) {
            self::refuseUnmet([ModePreconditions::unmet('from_approval_only', [], 'A property reaches the autonomous mode only from approval.')]);
        }
        if (!$this->capabilities->userCan($userId, self::MANAGE_CONNECTIONS)) {
            throw new ModeRefused(ModeRefused::NO_CAPABILITY, 'Switching to autonomous needs the connections capability.');
        }
        if (!hash_equals($property->id, trim($confirmation))) {
            throw new ModeRefused(ModeRefused::CONFIRMATION, 'Type the property\'s id to confirm the autonomous mode.');
        }
        $since = $this->events->lastTo($property->id, OperationEventRepository::MODE, PropertyMode::Approval->value)['at'] ?? null;
        $days = $since === null ? 0 : intdiv(max(0, $this->clock->now()->getTimestamp() - $since->getTimestamp()), 86400);
        $written = count(array_filter(
            $this->events->latest($property->id, OperationEventRepository::APPROVAL, 500),
            static fn (array $e): bool => $e['to'] === 'written' && ($since === null || $e['at'] >= $since),
        ));
        if ($days < $config->minApprovalDays || $written < $config->minWrittenPlans) {
            self::refuseUnmet([ModePreconditions::unmet(
                'approval_time',
                ['days_needed' => $config->minApprovalDays, 'plans_needed' => $config->minWrittenPlans, 'days' => $days, 'plans' => $written],
                sprintf('The property needs %d days in approval and %d approved plans written first (%d days and %d plans so far).', $config->minApprovalDays, $config->minWrittenPlans, $days, $written),
            )]);
        }
        self::refuseUnmet($this->preconditions->toAutonomous($property));
    }

    /**
     * @param list<array{code: string, params: array<string, int|string>, text: string}> $unmet
     *
     * @throws ModeRefused when a condition is not met
     */
    private static function refuseUnmet(array $unmet): void
    {
        if ($unmet !== []) {
            throw new ModeRefused(ModeRefused::CONDITIONS, implode(' ', array_column($unmet, 'text')), $unmet);
        }
    }

    private function apply(Property $property, PropertyMode $to, string $actor, string $reason): void
    {
        $this->properties->setMode($property->id, $to);
        $this->events->record($property->id, OperationEventRepository::MODE, $property->mode->value, $to->value, $reason, $actor, $this->clock->now());
        $this->approvals->expireAll($property->id, 'mode_changed');
    }

    /**
     * @param list<string> $capabilities
     */
    private function any(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->capabilities->userCan($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    private static function rank(PropertyMode $mode): int
    {
        return match ($mode) {
            PropertyMode::Shadow => 0,
            PropertyMode::Approval => 1,
            PropertyMode::Auto => 2,
        };
    }
}
