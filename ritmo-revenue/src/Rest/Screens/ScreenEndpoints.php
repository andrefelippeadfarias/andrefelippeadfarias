<?php

declare(strict_types=1);

namespace Ritmo\Rest\Screens;

use Closure;
use OutOfBoundsException;
use Ritmo\Admin\RefusalText;
use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Grid\Approvals\ApprovalBoard;

/**
 * What the REST routes of the central screens answer, as [HTTP status, body]: translated texts,
 * never an exception message, a secret, SQL or guest data. The capability and the nonce were
 * checked by the route; what one person may do with one object is checked here again by the use
 * cases.
 */
final class ScreenEndpoints
{
    public const ENTRY_PATTERN = '/^(item|deletion|listing)\|[A-Za-z0-9_-]{1,32}\|[A-Za-z0-9_-]{1,40}$/';

    private const FINAL = ['written', 'lapsed', 'expired', 'rejected', 'superseded'];

    /**
     * @param Closure(string): array{ok: bool, reason: string, text: string, tested_at: string, cached: bool} $test
     * @param Closure(string): string                                                                    $statusText
     */
    public function __construct(
        private readonly ApprovalBoard $board,
        private readonly AlertService $alerts,
        private readonly Closure $test,
        private readonly Closure $statusText,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function approvalStatus(int $id): array
    {
        $status = $this->board->status($id);
        if ($status === null) {
            return self::notFound();
        }
        $word = $status['expired'] ? 'expired' : $status['status'];

        return [200, [
            'id' => $status['id'],
            'status' => $word,
            'text' => ($this->statusText)($word),
            'final' => in_array($word, self::FINAL, true),
            'write_run_id' => $status['write_run_id'],
            'entries' => $status['entries'],
            'approved' => $status['approved'],
        ]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function selection(int $id, string $property, mixed $keys): array
    {
        $list = is_array($keys) ? array_values(array_filter($keys, static fn (mixed $k): bool => is_string($k) && preg_match(self::ENTRY_PATTERN, $k) === 1)) : [];
        if (!is_array($keys) || count($list) !== count($keys) || count($list) > 5000) {
            return [400, ['code' => 'ritmo_bad_input', 'message' => __('The selection is not valid.', 'ritmo-revenue')]];
        }
        try {
            $closed = $this->board->selection($property, $id, $list);
        } catch (ApprovalRefused $refused) {
            return [400, ['code' => 'ritmo_refused', 'message' => RefusalText::approval($refused)]];
        } catch (OutOfBoundsException) {
            return self::notFound();
        }

        return [200, [
            'approved' => $closed['approved'],
            'added' => $closed['added'],
            'split' => array_map(static fn (array $p): array => ['relation' => $p['relation'], 'night' => $p['night'], 'rooms' => $p['rooms']], $closed['split']),
            'impact' => ['nights' => $closed['impact']->nights, 'up' => $closed['impact']->up, 'down' => $closed['impact']->down],
        ]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function connectionTest(string $provider): array
    {
        $result = ($this->test)($provider);
        $body = ['ok' => $result['ok'], 'reason' => $result['reason'], 'text' => $result['text'], 'tested_at' => $result['tested_at']];

        return [$result['cached'] ? 429 : 200, $body + ($result['cached'] ? ['code' => 'ritmo_cooldown'] : [])];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function acknowledge(int $id, int $userId, bool $isApprover): array
    {
        try {
            $this->alerts->acknowledge($id, $userId, $isApprover);
        } catch (OutOfBoundsException) {
            return [403, ['code' => 'ritmo_forbidden', 'message' => __('This alert cannot be acknowledged by you.', 'ritmo-revenue')]];
        }

        return [200, ['id' => $id, 'status' => 'acknowledged', 'text' => __('Alert acknowledged.', 'ritmo-revenue')]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function resolve(int $id, int $userId, bool $isApprover): array
    {
        try {
            $this->alerts->resolve($id, $userId, $isApprover);
        } catch (OutOfBoundsException) {
            return [403, ['code' => 'ritmo_forbidden', 'message' => __('This alert cannot be resolved by you.', 'ritmo-revenue')]];
        }

        return [200, ['id' => $id, 'status' => 'resolved', 'text' => __('Alert resolved.', 'ritmo-revenue')]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function notFound(): array
    {
        return [404, ['code' => 'ritmo_not_found', 'message' => __('Not found.', 'ritmo-revenue')]];
    }
}
