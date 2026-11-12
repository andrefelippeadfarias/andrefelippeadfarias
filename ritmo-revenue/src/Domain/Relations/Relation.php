<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Relations\Config\ErrorList;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Params\BandParams;
use Ritmo\Domain\Relations\Params\BetweenMode;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\Params\RelationParams;
use Ritmo\Domain\Relations\Params\SwitchParams;

/**
 * One relation between room types of a property: the target room keeps a commercial logic
 * against other rooms. Immutable; built from its storage form by {@see Relation::fromArray()}.
 */
final class Relation
{
    /** Relation ids: lowercase letters, digits, "-" and "_", up to 64 characters. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** Ids of owner orders cited or required by a relation. */
    public const REF_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/';

    public const DEFAULT_PRIORITY = 100;
    private const MAX_PRIORITY = 1000;
    private const MAX_LABEL = 120;

    /**
     * @param list<RelationStage> $stages
     * @param string|null         $orderRef owner order cited on the items the relation writes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $enabled,
        public readonly string $propertyId,
        public readonly RelationType $type,
        public readonly string $target,
        public readonly int $priority,
        public readonly array $stages,
        public readonly ScanMode $scan,
        public readonly StrategyNights $strategyNights,
        public readonly RelationScope $scope,
        public readonly RelationParams $params,
        public readonly ?string $orderRef = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data storage form
     *
     * @throws InvalidConfig with every error found
     */
    public static function fromArray(array $data): self
    {
        $errors = new ErrorList();
        $relation = self::read(new Reader($data, '', $errors));
        $errors->throwIfAny('relation');

        return $relation;
    }

    /**
     * Reads a relation and records its errors in the reader's list; the result is only
     * meaningful when no error was recorded.
     */
    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown([
            'id', 'label', 'enabled', 'property', 'type', 'target', 'priority', 'stages', 'scan', 'strategy_nights',
            'scope', 'params', 'order_ref',
        ]);
        $type = $reader->choice('type', RelationType::class, null);
        $params = self::readParams($type, $reader->group('params'));
        $stages = $reader->has('stages') ? $reader->choices('stages', RelationStage::class) : [RelationStage::Coherence];
        self::checkStages($reader, $type, $params, $stages);

        return new self(
            $reader->text('id', null, self::ID_PATTERN),
            $reader->text('label', '', null, self::MAX_LABEL),
            $reader->flag('enabled', true),
            $reader->text('property', null, Identifier::CODE_PATTERN),
            $type,
            $reader->text('target', null, Identifier::CODE_PATTERN),
            $reader->integer('priority', self::DEFAULT_PRIORITY, 0, self::MAX_PRIORITY),
            $stages,
            $reader->choice('scan', ScanMode::class, ScanMode::Horizon),
            $reader->choice('strategy_nights', StrategyNights::class, StrategyNights::Apply),
            RelationScope::read($reader->group('scope')),
            $params,
            $reader->optionalText('order_ref', self::REF_PATTERN),
        );
    }

    public function runsIn(RelationStage $stage): bool
    {
        return in_array($stage, $this->stages, true);
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
            'type' => $this->type->value,
            'target' => $this->target,
            'priority' => $this->priority,
            'stages' => array_map(static fn (RelationStage $stage): string => $stage->value, $this->stages),
            'scan' => $this->scan->value,
            'strategy_nights' => $this->strategyNights->value,
            'scope' => $this->scope->toArray(),
            'params' => $this->params->toArray(),
            'order_ref' => $this->orderRef,
        ];
    }

    private static function readParams(RelationType $type, Reader $params): RelationParams
    {
        return match ($type) {
            RelationType::AtLeast => AtLeastParams::read($params),
            RelationType::Between => BetweenParams::read($params),
            RelationType::PremiumOverMax => PremiumParams::read($params),
            RelationType::DiscountBand => BandParams::read($params),
            RelationType::OccupancySwitch => SwitchParams::read($params),
        };
    }

    /**
     * @param list<RelationStage> $stages
     */
    private static function checkStages(Reader $reader, RelationType $type, RelationParams $params, array $stages): void
    {
        if ($stages === []) {
            $reader->error('stages', ValidationError::RANGE, 'must have at least one stage');
        }
        $longHorizon = in_array(RelationStage::Strategy, $stages, true) || in_array(RelationStage::Audit, $stages, true);
        foreach ($stages as $stage) {
            if (!in_array($stage, $type->allowedStages(), true)) {
                $reader->error('stages', ValidationError::CHOICE, sprintf('a %s relation cannot run in the %s stage', $type->value, $stage->value));
            }
        }
        if ($longHorizon && $params instanceof BetweenParams && $params->mode !== BetweenMode::Path) {
            $reader->error('stages', ValidationError::CHOICE, 'only a between relation of mode path runs in the strategy and audit stages');
        }
        if ($longHorizon && $params instanceof AtLeastParams && !$params->derive) {
            $reader->error('stages', ValidationError::CHOICE, 'only a derived at_least relation runs in the strategy and audit stages');
        }
    }
}
