<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Write\Calendar\PmsCalendarChanges;
use Ritmo\Application\Write\Schedule\WriteSchedule;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Domain\Write\WriteConfig;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\WriteAuthority;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Contracts\WriteRequest;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Plan\Payload\PayloadBuilder;
use Ritmo\Domain\Plan\Payload\Slicer;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use WeakMap;

/**
 * The only issuer of write tokens, and their live registry.
 *
 * issue() builds the steps of the validated plan itself (payload, slices, schedule) and gives a
 * token for exactly those steps only when, with the clock read once at the start:
 * the plan was sealed by the Validator of this process (G1), is of the property's today (G2),
 * was validated recently (G3) over reads still fresh (G4) and over the listing bases the run read
 * (the context's rooms carry them: a plan over another base is refused); the emergency switch is released
 * (G5); the authorization allows it now (G6: autonomous mode, or a person's approval of this
 * plan; never shadow); tonight's price cannot rise (G8); every field is allowed (G9); and the run
 * holds the site's write lock (G12). A listing change goes only with a person's approval naming
 * it and with listing changes enabled (G7): otherwise it is left out, the rest goes on.
 *
 * The registry maps each token object (weakly) to what it may do. verify() is what the
 * connectors call before every write: the token must be live (issued here, not revoked, not
 * expired, the property's day unchanged, the emergency switch released) and the request inside a
 * step not used yet. A token made any other way is not in the registry and is refused.
 */
final class WriteGate implements WriteAuthority
{
    /** A plan's base and the base read are the same within a cent. */
    private const BASE_SLACK = 0.005;

    /** @var WeakMap<WriteToken, TokenGrant>|null */
    private static ?WeakMap $grants = null;

    public function __construct(
        private readonly Clock $clock,
        private readonly EmergencySwitch $switch,
        private readonly WriteConfig $config = new WriteConfig(),
        private readonly PlanConfig $planConfig = new PlanConfig(),
    ) {
    }

    /**
     * The PMS steps come from the PMS changes sealed with the plan (never from a list of the
     * caller): a change the validator did not seal cannot get a token.
     *
     * @param list<string> $recalculate listing ids to recalculate before their writes
     *
     * @throws WriteRefused when the plan may not be written
     */
    public function issue(ValidatedPlan $plan, WriteAuthorization $auth, WriteContext $context, array $recalculate = []): IssuedToken
    {
        $now = $this->clock->now();
        $today = $this->clock->today($context->property->timezone);
        $this->checkPlan($plan, $context, $now);
        if ($this->switch->isEngaged()) {
            throw new WriteRefused(WriteRefused::KILL_SWITCH, 'The emergency switch is engaged.');
        }
        $hash = PlanHash::of($plan->plan);
        $refusal = $auth->refusal($context, $hash, $today);
        if ($refusal !== null) {
            throw new WriteRefused($refusal, 'The authorization does not allow this plan now.');
        }

        [$steps, $dropped] = $this->listingSteps($this->steps($plan, $context, $recalculate), $auth);
        if ($steps === []) {
            throw new WriteRefused(WriteRefused::NOTHING_TO_WRITE, 'The plan has nothing this token could write.');
        }
        (new TonightGuard())->check($steps, Days::key($today), $context->tonight, $context->bases());
        (new FieldLocks())->check($steps, $context->rooms);
        if (($context->lockHeld)() !== true) {
            throw new WriteRefused(WriteRefused::LOCK_NOT_HELD, 'The run does not hold the site\'s write lock.');
        }

        $expires = min($now->modify(sprintf('+%d minutes', $this->config->tokenTtlMinutes)), self::midnightAfter($today, $context->property->timezone));
        $token = self::mint(bin2hex(random_bytes(16)));
        self::$grants ??= new WeakMap();
        self::$grants[$token] = new TokenGrant(
            $context->property->id,
            $context->property->timezone,
            Days::key($today),
            $expires,
            array_map(static fn (WriteStep $s): WriteRequest => $s->request(), $steps),
        );

        return new IssuedToken($token, $steps, $dropped, $hash, $expires, $auth->actor());
    }

    public function isLive(WriteToken $token): bool
    {
        return $this->liveGrant($token) !== null;
    }

    public function verify(WriteToken $token, WriteRequest $request): void
    {
        $grant = $this->liveGrant($token);
        if ($grant === null) {
            throw WriteBlocked::forRequest('WRITE', $request->operation->value, 'the write token is not live');
        }
        if (!$grant->use($request)) {
            throw WriteBlocked::forRequest('WRITE', $request->operation->value, 'the request is outside what the token allows, or was already sent');
        }
    }

    public function claim(WriteToken $token, string $method, string $url, ?string $body): bool
    {
        return $this->liveGrant($token)?->claim(hash('sha256', $method . "\n" . $url . "\n" . ($body ?? ''))) === true;
    }

    /**
     * Ends a token: at the end of a run, or when it stops.
     */
    public function revoke(WriteToken $token): void
    {
        $grant = self::$grants[$token] ?? null;
        $grant?->revoke();
    }

    private function liveGrant(WriteToken $token): ?TokenGrant
    {
        $grant = self::$grants[$token] ?? null;
        if ($grant === null || $grant->isRevoked()) {
            return null;
        }
        $over = $this->clock->now() >= $grant->expiresAt || Days::key($this->clock->today($grant->timezone)) !== $grant->today || $this->switch->isEngaged();
        if ($over) {
            $grant->revoke();

            return null;
        }

        return $grant;
    }

    /**
     * @throws WriteRefused
     */
    private function checkPlan(ValidatedPlan $plan, WriteContext $context, DateTimeImmutable $now): void
    {
        $refusal = match (true) {
            !Validator::sealed($plan) => [WriteRefused::UNSEALED_PLAN, 'The plan was not validated in this process.'],
            $plan->plan->propertyId !== $context->property->id => [WriteRefused::WRONG_PROPERTY, 'The plan is of another property.'],
            !$plan->isCurrent($this->clock) || $plan->timezone !== $context->property->timezone => [WriteRefused::STALE_PLAN, 'The plan is not of the property\'s today: it is redone, never written.'],
            self::minutes($plan->validatedAt, $now) > $this->config->maxValidationAgeMinutes => [WriteRefused::STALE_VALIDATION, 'The plan was validated too long ago.'],
            $context->readAt > $now || self::minutes($context->readAt, $now) > $this->config->maxReadAgeMinutes => [WriteRefused::STALE_READS, 'The calendars the plan was decided on are too old.'],
            default => null,
        };
        if ($refusal !== null) {
            throw new WriteRefused($refusal[0], $refusal[1]);
        }
        foreach ($plan->bases as $code => $base) {
            $read = $context->rooms[$code] ?? null;
            if ($read === null || abs($read->base - $base) > self::BASE_SLACK) {
                throw new WriteRefused(WriteRefused::BASE_CHANGED, 'The plan\'s percentages are over another listing base than the one read: it is validated again, never written.', (string) $code);
            }
        }
    }

    /**
     * @param list<string> $recalculate
     *
     * @return list<WriteStep>
     */
    private function steps(ValidatedPlan $plan, WriteContext $context, array $recalculate): array
    {
        try {
            $pms = PmsCalendarChanges::of($plan->plan->pmsChanges, $context->rooms);
        } catch (InvalidArgumentException) {
            throw new WriteRefused(WriteRefused::WRONG_PROPERTY, 'A PMS change of the plan is of a room the run did not read.');
        }
        $payload = (new PayloadBuilder($context->pricingPms, $this->planConfig))->build($plan, $context->property, array_values($context->rooms));
        $slices = (new Slicer($this->config->maxDatesPerCall))->slice($payload, $plan->today);

        return (new WriteSchedule($this->config))->build($slices, $plan->today, $pms, $recalculate);
    }

    /**
     * @param list<WriteStep> $steps
     *
     * @return array{0: list<WriteStep>, 1: list<array{step: WriteStep, reason: string}>}
     */
    private function listingSteps(array $steps, WriteAuthorization $auth): array
    {
        $kept = $dropped = [];
        foreach ($steps as $step) {
            if ($step->operation === WriteOperation::ListingUpdate && !$this->listingAllowed($step, $auth)) {
                $dropped[] = ['step' => $step, 'reason' => WriteRefused::LISTING_NEEDS_APPROVAL];
                continue;
            }
            $kept[] = $step->at(count($kept) + 1);
        }

        return [$kept, $dropped];
    }

    private function listingAllowed(WriteStep $step, WriteAuthorization $auth): bool
    {
        if (!$this->config->listingChangesEnabled || !$auth->isHuman()) {
            return false;
        }
        foreach ($step->fields() as $field) {
            if (!$auth->allowsListingChange($step->roomCode, $field)) {
                return false;
            }
        }

        return true;
    }

    private static function minutes(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        return ($to->getTimestamp() - $from->getTimestamp()) / 60;
    }

    private static function midnightAfter(DateTimeImmutable $today, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable(Days::key($today) . ' 00:00:00', new DateTimeZone($timezone)))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Creates a token through its private constructor (this method is the only place that can).
     */
    private static function mint(string $id): WriteToken
    {
        $create = Closure::bind(static fn (string $id): WriteToken => new WriteToken($id), null, WriteToken::class);

        return $create($id);
    }
}
