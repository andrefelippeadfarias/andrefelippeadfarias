<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use Ritmo\Application\Market\MarketSettings;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleReferences;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Market\Series\LadderSeriesCheck;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\RelationValidator;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;

/**
 * The full check of candidate rules before a version is saved (spec 05, 5.5.1 step 2): the
 * schema and every module's rules (RuleSetValidator), the room relations against the rooms of
 * the site (with the cycle check), the rooms and properties the rules name, and the market
 * series and percentiles the sales ladder cites.
 */
final class RuleChecks
{
    public function __construct(private readonly PropertyRepository $properties, private readonly RoomTypeRepository $rooms)
    {
    }

    /**
     * @param array<string, mixed> $candidate complete rules
     *
     * @return list<ValidationError> empty when the rules may be saved
     */
    public function errors(array $candidate): array
    {
        try {
            $rules = RuleSet::fromArray($candidate);
        } catch (InvalidConfig $invalid) {
            return $invalid->errors();
        }
        $rooms = $this->rooms->all();
        $propertyIds = array_map(static fn ($p): string => $p->id, $this->properties->all());
        $errors = [
            ...RuleReferences::unknown($rules->toArray(), array_map(static fn (RoomType $r): string => $r->code, $rooms), $propertyIds),
            ...(new RelationValidator())->validate($rules->relations(), $rooms, $propertyIds),
        ];
        try {
            $errors = [...$errors, ...LadderSeriesCheck::errors((array) ($rules->toArray()['ladder'] ?? []), MarketSettings::of($rules))];
        } catch (InvalidConfig $invalid) {
            $errors = [...$errors, ...$invalid->errors()];
        }

        return $errors;
    }
}
