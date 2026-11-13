<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Changes;

/**
 * "Prepare the undo" of the Change log (admin spec 5.11, decision D9). The integration implements
 * it on UndoService::request(), which proposes an Undo request on the Approvals screen
 * ("request_id" set, nothing written until a person approves it). Until then the screen runs the
 * M3 undo, approved by the person who asked, through the write gate ("request_id" null).
 */
interface UndoRequests
{
    /**
     * @param list<int> $changeIds
     *
     * @return array{request_id: int|null, status: string, written: int, undone: int, left_out: list<string>} left_out: reason codes of what was left out
     */
    public function request(string $propertyId, array $changeIds, int $userId): array;
}
