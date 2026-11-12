<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * The stay fields a pricing connector can write by date and pushes on to the PMS.
 *
 * When the pricing system overwrites a PMS field, a change of that field written only to the PMS
 * is undone by the next push: the change must go to the pricing system too ("also pricing").
 * The connector declares what it can carry; the configuration says for which rooms the push is on.
 */
final class StaySyncCapability
{
    /** @var list<StaySyncField> */
    public readonly array $fields;

    public function __construct(StaySyncField ...$fields)
    {
        $unique = [];
        foreach ($fields as $field) {
            $unique[$field->value] = $field;
        }
        $this->fields = array_values($unique);
    }

    public static function none(): self
    {
        return new self();
    }

    public function supports(StaySyncField $field): bool
    {
        return in_array($field, $this->fields, true);
    }
}
