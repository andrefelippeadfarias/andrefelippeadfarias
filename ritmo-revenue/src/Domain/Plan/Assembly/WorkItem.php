<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use DateTimeImmutable;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\OrphanClaim;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\Reason;
use Ritmo\Domain\Support\Num;

/**
 * One room night of the plan while it is being assembled: the passes after the pricing (the
 * relations, the listing top, the ceiling release) change it in place; {@see toPlanItem()} gives
 * the immutable item at the end.
 */
final class WorkItem
{
    public const PACE_STEP = 'PACE_STEP';
    public const LADDER_PRICE = 'LADDER_PRICE';
    public const SOLD_OUT_PROTECTION = 'SOLD_OUT_PROTECTION';
    public const RELATION_AT_LEAST = 'RELATION_AT_LEAST';
    public const RELATION_BETWEEN = 'RELATION_BETWEEN';
    public const RELATION_PREMIUM_OVER_MAX = 'RELATION_PREMIUM_OVER_MAX';
    public const RELATION_DISCOUNT_BAND = 'RELATION_DISCOUNT_BAND';
    public const RELATION_OCCUPANCY_SWITCH = 'RELATION_OCCUPANCY_SWITCH';

    /** The reason code of a relation that changed the item, by the coherence reason. */
    private const RELATION_CODES = [
        Reason::OCCUPANCY_SWITCH => self::RELATION_OCCUPANCY_SWITCH,
        Reason::DISCOUNT_BAND => self::RELATION_DISCOUNT_BAND,
        Reason::PREMIUM_OVER_MAX => self::RELATION_PREMIUM_OVER_MAX,
        Reason::BETWEEN => self::RELATION_BETWEEN,
        Reason::AT_LEAST => self::RELATION_AT_LEAST,
    ];

    /** The code an item shows: the protection first, then the relations, then what priced it. */
    private const PRECEDENCE = [
        self::SOLD_OUT_PROTECTION,
        self::RELATION_OCCUPANCY_SWITCH,
        self::RELATION_DISCOUNT_BAND,
        self::RELATION_PREMIUM_OVER_MAX,
        self::RELATION_BETWEEN,
        self::RELATION_AT_LEAST,
    ];

    /** @var list<string> reason codes of the relations that changed the item */
    private array $relations = [];

    public ?int $minStay = null;

    public ?string $checkInMask = null;

    public ?string $checkOutMask = null;

    public ?OrphanClaim $orphan = null;

    public bool $keepReason = false;

    /**
     * @param array<string, mixed> $params details of the reason (trace, step, authority...)
     * @param bool                 $engineStep   a pace step of the engine (the ledger records it)
     * @param bool                 $strategy     priced by a sales ladder strategy
     * @param bool                 $protection   protects a sold-out night
     */
    public function __construct(
        public readonly string $room,
        public readonly DateTimeImmutable $night,
        public readonly float $priceBefore,
        public ?float $minPrice,
        public ?float $maxPrice,
        public readonly string $reasonCode,
        public array $params,
        public readonly NightState $before,
        public ?string $orderId = null,
        public readonly bool $engineStep = false,
        public readonly bool $strategy = false,
        public readonly bool $protection = false,
    ) {
    }

    public function key(): string
    {
        return PlanItem::keyOf($this->room, $this->night);
    }

    public function toDraft(): DraftItem
    {
        return new DraftItem($this->room, $this->night, $this->priceBefore, $this->minPrice, $this->maxPrice, $this->orderId, $this->engineStep, $this->strategy, $this->protection);
    }

    /**
     * Takes what the coherence stage did to the item's draft.
     */
    public function absorb(DraftItem $draft): void
    {
        $this->minPrice = $draft->floor();
        $this->maxPrice = $draft->ceiling();
        $this->orderId = $draft->orderRef();
        foreach ($draft->reasons() as $reason) {
            $code = self::RELATION_CODES[$reason->code] ?? null;
            if ($code !== null && !in_array($code, $this->relations, true)) {
                $this->relations[] = $code;
            }
        }
        if ($draft->reasons() !== []) {
            $this->params['relations'] = array_map(static fn (Reason $r): array => ['code' => $r->code, 'args' => $r->args], $draft->reasons());
        }
    }

    /**
     * A new item the coherence stage created.
     */
    public static function fromDraft(DraftItem $draft, NightState $before): self
    {
        $codes = array_values(array_filter(array_map(static fn (Reason $r): ?string => self::RELATION_CODES[$r->code] ?? null, $draft->reasons())));
        $item = new self($draft->room, $draft->night, $draft->priceBefore, $draft->floor(), $draft->ceiling(), $codes[0] ?? self::RELATION_BETWEEN, [], $before, $draft->orderRef());
        $item->absorb($draft);

        return $item;
    }

    /**
     * The code the item shows: the sold-out protection, else the relation that changed it, else
     * what priced it.
     */
    public function primaryCode(): string
    {
        foreach (self::PRECEDENCE as $code) {
            if ($code === $this->reasonCode || in_array($code, $this->relations, true)) {
                return $code;
            }
        }

        return $this->reasonCode;
    }

    /**
     * @return list<string>
     */
    public function relationCodes(): array
    {
        return $this->relations;
    }

    /**
     * The floor as the pricing system applies it: a whole percentage of the base, rounded up.
     */
    public static function applied(float $amount, float $base): float
    {
        return Num::percentToAmount((float) Num::floorToPercent($amount, $base), $base, 2);
    }

    public function toPlanItem(bool $readsOccupancy): PlanItem
    {
        $params = $this->params + ['priced_by' => $this->reasonCode];
        if ($this->relations !== []) {
            $params['relation_codes'] = $this->relations;
        }

        return new PlanItem(
            $this->room,
            $this->night,
            $this->minPrice,
            $this->maxPrice,
            $this->minStay,
            $this->primaryCode(),
            $params,
            $this->before,
            $this->checkInMask,
            $this->checkOutMask,
            $this->orderId,
            $this->orphan,
            null,
            $this->keepReason,
            '',
            $readsOccupancy,
        );
    }
}
