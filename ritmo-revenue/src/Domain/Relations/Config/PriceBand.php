<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Config;

use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Relations\Relation;

/**
 * A market band of one room ("price_bands.items"): the room's price should stay between two
 * amounts on some night types, measured today and copied at most max_days_out ahead.
 *
 * Only the relations read it: the band ceiling caps the target of a premium relation and the
 * audit proposes it.
 */
final class PriceBand
{
    private const MAX_AMOUNT = 1000000.0;

    /**
     * @param list<NightType> $nightTypes          night types of the band (a holiday only when listed)
     * @param float           $minAmount           lowest price, in $pricePlan
     * @param bool            $skipOnStrategyNights no band on nights priced by the sales ladder
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $enabled,
        public readonly string $propertyId,
        public readonly string $room,
        public readonly array $nightTypes,
        public readonly PricePlan $pricePlan,
        public readonly float $minAmount,
        public readonly float $maxAmount,
        public readonly int $maxDaysOut = 60,
        public readonly bool $skipOnStrategyNights = true,
    ) {
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown([
            'id', 'label', 'enabled', 'property', 'room', 'night_types', 'price_plan', 'min_amount', 'max_amount',
            'max_days_out', 'skip_on_strategy_nights',
        ]);
        $min = $reader->number('min_amount', null, 0.01, self::MAX_AMOUNT);
        $max = $reader->number('max_amount', null, 0.01, self::MAX_AMOUNT);
        if ($min > $max) {
            $reader->error('max_amount', ValidationError::ORDER, 'must not be below the lowest price');
        }

        return new self(
            $reader->text('id', null, Relation::ID_PATTERN),
            $reader->text('label', '', null, 120),
            $reader->flag('enabled', true),
            $reader->text('property', null, Identifier::CODE_PATTERN),
            $reader->text('room', null, Identifier::CODE_PATTERN),
            $reader->choices('night_types', NightType::class),
            $reader->choice('price_plan', PricePlan::class, PricePlan::PublicRate),
            $min,
            $max,
            $reader->integer('max_days_out', 60, 0, 730),
            $reader->flag('skip_on_strategy_nights', true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'property' => $this->propertyId,
            'room' => $this->room,
            'night_types' => array_map(static fn (NightType $type): string => $type->value, $this->nightTypes),
            'price_plan' => $this->pricePlan->value,
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'max_days_out' => $this->maxDaysOut,
            'skip_on_strategy_nights' => $this->skipOnStrategyNights,
        ];
    }
}
