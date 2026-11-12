<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Relations\MemberPolicy;
use Ritmo\Domain\Relations\RoomSet;

/**
 * The target above the dearest room of a set, by a percent that depends on the night type.
 */
final class PremiumParams implements RelationParams
{
    private const MAX_PERCENT = 500.0;
    private const MAX_EPSILON = 100.0;

    /**
     * @param array<string, float> $premiumPercent percent by NightType value
     * @param float                $epsilonAmount  added to the floor in the coherence stage so the target is strictly above
     */
    public function __construct(
        public readonly RoomSet $set,
        public readonly array $premiumPercent,
        public readonly float $epsilonAmount = 0.0,
        public readonly MemberPolicy $members = MemberPolicy::FreeOnly,
    ) {
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown(['set', 'premium_percent', 'epsilon_amount', 'members']);
        $percents = $reader->group('premium_percent');
        $percents->rejectUnknown(array_map(static fn (NightType $type): string => $type->value, NightType::cases()));
        $premium = [];
        foreach (NightType::cases() as $type) {
            $premium[$type->value] = $percents->number($type->value, 0.0, 0.0, self::MAX_PERCENT);
        }

        return new self(
            RoomSet::read($reader->group('set')),
            $premium,
            $reader->number('epsilon_amount', 0.0, 0.0, self::MAX_EPSILON),
            $reader->choice('members', MemberPolicy::class, MemberPolicy::FreeOnly),
        );
    }

    /**
     * The premium of a night type as a fraction (0.2 = 20%).
     */
    public function premiumFor(NightType $type): float
    {
        return $this->premiumPercent[$type->value] / 100;
    }

    public function toArray(): array
    {
        return [
            'set' => $this->set->toArray(),
            'premium_percent' => $this->premiumPercent,
            'epsilon_amount' => $this->epsilonAmount,
            'members' => $this->members->value,
        ];
    }
}
