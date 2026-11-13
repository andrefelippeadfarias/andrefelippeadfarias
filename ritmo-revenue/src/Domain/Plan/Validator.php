<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\Validation\AuthorityCheck;
use Ritmo\Domain\Plan\Validation\BandCheck;
use Ritmo\Domain\Plan\Validation\DeletionCheck;
use Ritmo\Domain\Plan\Validation\EffectCheck;
use Ritmo\Domain\Plan\Validation\FloorCheck;
use Ritmo\Domain\Plan\Validation\IssueLog;
use Ritmo\Domain\Plan\Validation\ItemAuthority;
use Ritmo\Domain\Plan\Validation\ItemCheck;
use Ritmo\Domain\Plan\Validation\ItemFacts;
use Ritmo\Domain\Plan\Validation\LastMinuteCheck;
use Ritmo\Domain\Plan\Validation\ListingCheck;
use Ritmo\Domain\Plan\Validation\MaskCheck;
use Ritmo\Domain\Plan\Validation\OrphanProofCheck;
use Ritmo\Domain\Plan\Validation\PriceBoundsCheck;
use Ritmo\Domain\Plan\Validation\RelationOrderCheck;
use Ritmo\Domain\Plan\Validation\StayCheck;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use WeakMap;

/**
 * The plan validator: every safety rule a plan must pass before anything is written. It is the
 * only way to get a ValidatedPlan.
 *
 * Any error stops the whole plan; ValidationResult::rejected() lists the entries to drop for a
 * second validation. Per entry:
 *
 * - shape: the plan is of today (by the clock, in the property's timezone), rooms of the property,
 *   one entry per room night, a reason, no night in the past;
 * - the owner order it cites (Validation\AuthorityCheck);
 * - floors (FloorCheck, LastMinuteCheck), prices (PriceBoundsCheck), stay and orphan nights
 *   (StayCheck, OrphanProofCheck), arrival and departure days (MaskCheck), effect, fresh calendar
 *   and rise (EffectCheck);
 * - over the plan: the discount bands of the relations (BandCheck), the deletions (DeletionCheck)
 *   and the listing changes (ListingCheck); the order between related rooms after refusals
 *   (RelationOrderCheck, when the context carries the relations).
 *
 * Floors are compared as the pricing system will apply them: a whole percentage over the base,
 * rounded up (PercentRounding).
 *
 * Reference: validar_plano.Validador (validar, validar_data, validar_apagar, validar_anuncio,
 * discount-band check) and the effect of pace.sem_efeito. VALIDATOR.md lists the rules and where the
 * plugin decides differently.
 */
final class Validator
{
    /** @var WeakMap<ValidatedPlan, true>|null the plans sealed in this process */
    private static ?WeakMap $sealedPlans = null;

    private readonly PlanConfig $config;

    private readonly PriceFactor $factors;

    private readonly HolidayCalendar $calendar;

    private readonly PercentRounding $rounding;

    public function __construct(private readonly RuleSet $rules, private readonly Clock $clock, ?PlanConfig $config = null)
    {
        $this->config = $config ?? new PlanConfig();
        $this->factors = new PriceFactor($rules);
        $this->calendar = new HolidayCalendar($rules);
        $this->rounding = new PercentRounding();
    }

    /**
     * @param list<RoomType> $rooms the room types of the property
     *
     * @throws InvalidArgumentException when the plan is of another property
     */
    public function validate(Plan $plan, Property $property, array $rooms, ?ValidationContext $context = null): ValidationResult
    {
        if ($plan->propertyId !== $property->id) {
            throw new InvalidArgumentException(sprintf('The plan is for property "%s", not "%s".', $plan->propertyId, $property->id));
        }
        $context ??= new ValidationContext();
        $log = new IssueLog();
        $today = $this->clock->today($property->timezone);
        if (Days::key($plan->today) !== Days::key($today)) {
            $log->add(true, PlanIssue::STALE_PLAN, 'The plan was computed for another day and must be redone.', null, null, null, [
                'plan_today' => Days::key($plan->today),
                'today' => Days::key($today),
            ], IssueSubject::Plan);
        }
        $byCode = [];
        foreach ($rooms as $room) {
            if ($room->propertyId === $property->id) {
                $byCode[$room->code] = $room;
            }
        }

        $authority = new AuthorityCheck($context->orders, $context->strategies, $log);
        $facts = $this->checkItems($plan, $byCode, $today, $property->timezone, $context, $authority, $log);
        if ($context->bands !== null) {
            (new BandCheck($context->bands, $context->relationReport, $context->calendar, $log))->check($facts);
        }
        if ($context->relations !== null) {
            (new RelationOrderCheck($context->relations, $context->calendar, $context->relationReport, $today, $log))->check($facts);
        }
        (new DeletionCheck($this->calendar, $context->stay, $log, $authority))->check($plan->deletions, $byCode, $today);
        (new ListingCheck($this->rules, $authority, $log))->check($plan->listingChanges, $byCode, $context->weeklyReference, $today);

        $errors = $log->errors();

        return new ValidationResult($errors, $log->warnings(), $errors === [] ? $this->seal($plan, $today, $property->timezone, $byCode, $log->warnings()) : null);
    }

    /**
     * @param array<string, RoomType> $byCode
     *
     * @return list<ItemFacts> the items that passed the shape checks
     */
    private function checkItems(Plan $plan, array $byCode, DateTimeImmutable $today, string $timezone, ValidationContext $context, AuthorityCheck $authority, IssueLog $log): array
    {
        $checks = $this->itemChecks($context, $today, $timezone, $log);
        $deletedFirst = [];
        foreach ($plan->deletions as $deletion) {
            $deletedFirst[$deletion->key()] = true;
        }
        $facts = [];
        $seen = [];
        foreach ($plan->items as $index => $item) {
            $issue = static fn (string $code, string $message, array $params = []) => $log->add(true, $code, $message, $index, $item->roomCode, $item->date, $params, IssueSubject::Item);
            $room = $byCode[$item->roomCode] ?? null;
            if ($room === null) {
                $issue(PlanIssue::UNKNOWN_ROOM, sprintf('Room "%s" is not a room type of this property.', $item->roomCode));
                continue;
            }
            if (isset($seen[$item->key()])) {
                $issue(PlanIssue::DUPLICATE_ITEM, 'There is already an item for this room night; the later one would silently overwrite it.', ['first_item' => $seen[$item->key()]]);
            }
            $seen[$item->key()] ??= $index;
            if ($item->reasonCode === '') {
                $issue(PlanIssue::MISSING_REASON, 'The item has no reason code for the change log.');
            }
            $daysOut = Days::diff($today, $item->date);
            if ($daysOut < 0) {
                $issue(PlanIssue::PAST_DATE, 'The night is in the past.');
                continue;
            }
            $f = $this->facts($index, $item, $room, $daysOut, $authority->resolve($index, $room->code, $item->date, $item->orderId), $context, isset($deletedFirst[$item->key()]));
            foreach ($checks as $check) {
                $check->check($f);
            }
            $facts[] = $f;
        }

        return $facts;
    }

    /**
     * @return list<ItemCheck>
     */
    private function itemChecks(ValidationContext $context, DateTimeImmutable $today, string $timezone, IssueLog $log): array
    {
        $proofs = new OrphanProofCheck($today, $timezone, $context->calendar);

        return [
            new FloorCheck($this->rules, $this->factors, $this->config, $context->strategies, new LastMinuteCheck($this->config->lastMinute, $log), $log),
            new PriceBoundsCheck($this->rules, $this->config, $log),
            new StayCheck($context->stay, $this->config, $proofs, $log),
            new MaskCheck($context->stay, $log),
            new EffectCheck($this->config, $context->calendar, $context->acceptStaleCalendar, $this->clock->now(), $log),
        ];
    }

    private function facts(int $index, PlanItem $item, RoomType $room, int $daysOut, ItemAuthority $authority, ValidationContext $context, bool $afterDeletion): ItemFacts
    {
        $fresh = $context->calendar?->night($room->code, $item->date);
        $state = $item->before ?? ($fresh === null ? null : new NightState($fresh->price, $fresh->floor));

        return new ItemFacts(
            $index,
            $this->rounding->convert($item, $room),
            $room,
            $daysOut,
            $this->calendar->nightType($item->date),
            $this->calendar->holidayOn($item->date),
            $authority,
            $fresh,
            $state,
            $afterDeletion,
        );
    }

    /**
     * True when the plan was sealed by a Validator of this process: an object made some other way
     * (Reflection, a copy) is not a validated plan, whatever it holds.
     */
    public static function sealed(ValidatedPlan $plan): bool
    {
        return self::$sealedPlans !== null && isset(self::$sealedPlans[$plan]);
    }

    /**
     * Creates the ValidatedPlan through its private constructor (this method is the only place
     * that can) and records it as sealed.
     *
     * @param array<string, RoomType> $byCode   the room types the items were checked with
     * @param list<PlanIssue>         $warnings
     */
    private function seal(Plan $plan, DateTimeImmutable $today, string $timezone, array $byCode, array $warnings): ValidatedPlan
    {
        $bases = [];
        foreach ($plan->items as $item) {
            $bases[$item->roomCode] = $byCode[$item->roomCode]->base;
        }
        ksort($bases);
        $create = Closure::bind(
            static fn (Plan $plan, DateTimeImmutable $validatedAt, DateTimeImmutable $today, string $timezone, string $rulesHash, array $warnings, array $bases): ValidatedPlan
                => new ValidatedPlan($plan, $validatedAt, $today, $timezone, $rulesHash, $warnings, $bases),
            null,
            ValidatedPlan::class
        );
        $validated = $create($plan, $this->clock->now(), $today, $timezone, $this->rules->hash(), $warnings, $bases);
        self::$sealedPlans ??= new WeakMap();
        self::$sealedPlans[$validated] = true;

        return $validated;
    }
}
