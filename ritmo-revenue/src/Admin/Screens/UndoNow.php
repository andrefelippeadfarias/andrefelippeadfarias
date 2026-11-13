<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Application\Dashboard\Changes\UndoRequests;
use Ritmo\Application\Run\UndoWriter;
use Ritmo\Domain\Config\RuleSet;

/**
 * The undo as M3 does it (a run of kind "undo" under the property's lock, through the write
 * gate, approved by the person who asked), behind the port the integration replaces with the
 * Undo request of decision D9.
 */
final class UndoNow implements UndoRequests
{
    public function __construct(private readonly UndoWriter $writer, private readonly RuleSet $rules)
    {
    }

    public function request(string $propertyId, array $changeIds, int $userId): array
    {
        $result = $this->writer->undo($propertyId, $changeIds, $userId, $this->rules);

        return [
            'request_id' => null,
            'status' => $result['status'],
            'written' => $result['written'],
            'undone' => $result['undone'],
            'left_out' => array_values(array_map('strval', $result['refused'])),
        ];
    }
}
