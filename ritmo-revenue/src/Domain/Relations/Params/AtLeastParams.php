<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Relations\Margin;

/**
 * The target never below a reference room, plus an optional margin.
 */
final class AtLeastParams implements RelationParams
{
    private const MAX_UNITS = 1000;

    /**
     * @param int  $referenceMinUnitsLeft the relation acts only while the reference has this many units left
     *                                    (0: even sold out; 2: its last unit does not pull the target up)
     * @param bool $derive                on ladder nights the target's price IS the required price
     */
    public function __construct(
        public readonly string $reference,
        public readonly Margin $margin,
        public readonly int $referenceMinUnitsLeft = 1,
        public readonly bool $derive = false,
        public readonly DeriveMinimum $deriveMinimum = DeriveMinimum::None,
    ) {
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown(['reference', 'margin', 'reference_min_units_left', 'derive', 'derive_min']);

        return new self(
            $reader->text('reference', null, Identifier::CODE_PATTERN),
            $reader->has('margin') ? Margin::read($reader->group('margin')) : Margin::none(),
            $reader->integer('reference_min_units_left', 1, 0, self::MAX_UNITS),
            $reader->flag('derive', false),
            $reader->choice('derive_min', DeriveMinimum::class, DeriveMinimum::None),
        );
    }

    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'margin' => $this->margin->toArray(),
            'reference_min_units_left' => $this->referenceMinUnitsLeft,
            'derive' => $this->derive,
            'derive_min' => $this->deriveMinimum->value,
        ];
    }
}
