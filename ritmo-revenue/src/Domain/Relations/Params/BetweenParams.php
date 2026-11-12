<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Relations\MemberPolicy;

/**
 * The target between two rooms (mode path: from the cheaper one up to a point of the way to the
 * dearer one) or between two percents of one room (mode percent_of).
 */
final class BetweenParams implements RelationParams
{
    private const MAX_PERCENT = 1000.0;

    /**
     * @param string $lowReference  mode path: the room expected below (also the reference of percent_of)
     * @param string $highReference mode path: the room expected above; empty in mode percent_of
     */
    public function __construct(
        public readonly BetweenMode $mode,
        public readonly string $lowReference,
        public readonly string $highReference = '',
        public readonly float $positionMin = 0.0,
        public readonly float $positionMax = 1.0,
        public readonly float $minPercent = 100.0,
        public readonly float $maxPercent = 100.0,
        public readonly MemberPolicy $references = MemberPolicy::FreeOnly,
    ) {
    }

    public static function read(Reader $reader): self
    {
        $mode = $reader->choice('mode', BetweenMode::class, BetweenMode::Path);
        $policy = $reader->choice('references', MemberPolicy::class, MemberPolicy::FreeOnly);
        if ($mode === BetweenMode::PercentOf) {
            $reader->rejectUnknown(['mode', 'reference', 'min_percent', 'max_percent', 'references']);
            $min = $reader->number('min_percent', null, 1.0, self::MAX_PERCENT);
            $max = $reader->number('max_percent', null, 1.0, self::MAX_PERCENT);
            if ($min > $max) {
                $reader->error('max_percent', ValidationError::ORDER, 'must not be below the lowest percent');
            }

            return new self($mode, $reader->text('reference', null, Identifier::CODE_PATTERN), '', 0.0, 1.0, $min, $max, $policy);
        }
        $reader->rejectUnknown(['mode', 'low_reference', 'high_reference', 'position_min', 'position_max', 'references']);
        $low = $reader->text('low_reference', null, Identifier::CODE_PATTERN);
        $high = $reader->text('high_reference', null, Identifier::CODE_PATTERN);
        if ($low !== '' && $low === $high) {
            $reader->error('high_reference', ValidationError::REFERENCE, 'must differ from the lower room');
        }
        $positionMin = $reader->number('position_min', 0.0, 0.0, 1.0);
        $positionMax = $reader->number('position_max', 1.0, 0.0, 1.0);
        if ($positionMin > $positionMax) {
            $reader->error('position_max', ValidationError::ORDER, 'must not be below the lowest point of the way');
        }

        return new self($mode, $low, $high, $positionMin, $positionMax, 100.0, 100.0, $policy);
    }

    /**
     * @return list<string> the rooms whose price the relation reads
     */
    public function referenceRooms(): array
    {
        return $this->mode === BetweenMode::Path ? [$this->lowReference, $this->highReference] : [$this->lowReference];
    }

    public function toArray(): array
    {
        if ($this->mode === BetweenMode::PercentOf) {
            return [
                'mode' => $this->mode->value,
                'reference' => $this->lowReference,
                'min_percent' => $this->minPercent,
                'max_percent' => $this->maxPercent,
                'references' => $this->references->value,
            ];
        }

        return [
            'mode' => $this->mode->value,
            'low_reference' => $this->lowReference,
            'high_reference' => $this->highReference,
            'position_min' => $this->positionMin,
            'position_max' => $this->positionMax,
            'references' => $this->references->value,
        ];
    }
}
