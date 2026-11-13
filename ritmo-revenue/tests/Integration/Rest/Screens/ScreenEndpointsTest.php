<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Rest\Screens;

require_once dirname(__DIR__, 3) . '/Unit/Admin/Screens/WpStubs.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Grid\Approvals\ApprovalBoard;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;
use Ritmo\Rest\Screens\ScreenEndpoints;
use Ritmo\Tests\Unit\Application\Alerts\AlertsKit;
use Ritmo\Tests\Unit\Application\Grid\ScreenWorld;

/**
 * The answers of the REST routes of the central screens over a real database (SQLite, every
 * migration): status codes, translated texts, and nothing internal in a body (admin spec 6.3).
 */
final class ScreenEndpointsTest extends TestCase
{
    private ScreenWorld $world;

    private AlertsKit $alerts;

    /** @var list<string> */
    private array $tested = [];

    protected function setUp(): void
    {
        $this->world = new ScreenWorld();
        $this->world->property();
        $this->world->property('other');
        $this->world->room('A');
        $this->world->room('B');
        $this->alerts = new AlertsKit($this->world->db, $this->world->clock);
    }

    public function testTheStatusOfARequestAndOfAnUnknownOne(): void
    {
        $id = $this->request(4);

        [$status, $body] = $this->endpoints()->approvalStatus($id);
        self::assertSame(200, $status);
        self::assertSame('pending', $body['status']);
        self::assertSame('Pending', $body['text']);
        self::assertFalse($body['final']);
        self::assertSame(8, $body['entries']);

        [$status, $body] = $this->endpoints()->approvalStatus($id + 99);
        self::assertSame(404, $status);
        self::assertSame(['code' => 'ritmo_not_found', 'message' => 'Not found.'], $body);
    }

    public function testASelectionIsClosedAndTotalledWithoutWritingAnything(): void
    {
        $id = $this->request(4);
        $key = (new PlanEntry(PlanEntry::ITEM, 'A', ScreenWorld::TODAY, []))->key();

        [$status, $body] = $this->endpoints()->selection($id, 'inn', [$key]);

        self::assertSame(200, $status);
        self::assertContains($key, $body['approved']);
        self::assertSame(count($body['approved']), $body['impact']['nights']);
        self::assertSame(count($body['approved']), $body['impact']['down']);
        self::assertSame('pending', $this->world->approvals()->find($id)['status'] ?? null, 'a selection only answers; it decides nothing');
    }

    public function testABadSelectionIsRefusedWithAFixedText(): void
    {
        $id = $this->request(2);
        $good = (new PlanEntry(PlanEntry::ITEM, 'A', ScreenWorld::TODAY, []))->key();
        foreach (['not a list', [$good, 'item|A|<script>'], [$good, 7], array_fill(0, 5001, $good)] as $keys) {
            [$status, $body] = $this->endpoints()->selection($id, 'inn', $keys);
            self::assertSame(400, $status);
            self::assertSame('ritmo_bad_input', $body['code']);
        }

        [$status, $body] = $this->endpoints()->selection($id, 'other', [$good]);
        self::assertSame(404, $status, 'a request of another property is not found from this one');
        self::assertStringNotContainsString((string) $id, $body['message']);
    }

    public function testAConnectionTestAnswersOnceAndThenWaitsForTheCooldown(): void
    {
        [$status, $body] = $this->endpoints()->connectionTest('beds24');
        self::assertSame(200, $status);
        self::assertSame(['ok' => true, 'reason' => 'ok', 'text' => 'Connected.', 'tested_at' => '2026-11-13 10:00'], $body);

        [$status, $body] = $this->endpoints()->connectionTest('beds24');
        self::assertSame(429, $status);
        self::assertSame('ritmo_cooldown', $body['code']);
        self::assertSame(['beds24', 'beds24'], $this->tested);
    }

    public function testAnAlertIsAcknowledgedAndResolvedOnlyByWhoMay(): void
    {
        $plain = $this->alert(AlertCode::ConnectorDown, ['pricelabs']);
        $write = $this->alert(AlertCode::WriteFailed, ['run', '7']);
        $endpoints = $this->endpoints();

        self::assertSame(200, $endpoints->acknowledge($plain, 3, false)[0]);
        self::assertSame('acknowledged', $this->alerts->ledger->find($plain)?->status->value);
        [$status, $body] = $endpoints->acknowledge($write, 3, false);
        self::assertSame(403, $status, 'a write alert is acknowledged by an approver');
        self::assertSame('ritmo_forbidden', $body['code']);
        self::assertSame(403, $endpoints->resolve($plain, 3, false)[0]);
        self::assertSame(403, $endpoints->acknowledge($plain + 99, 3, true)[0]);

        [$status, $body] = $endpoints->resolve($write, 4, true);
        self::assertSame(200, $status);
        self::assertSame('Alert resolved.', $body['text']);
        self::assertSame('resolved', $this->alerts->ledger->find($write)?->status->value);
    }

    public function testNoBodyCarriesAnExceptionSqlOrATableName(): void
    {
        $id = $this->request(2);
        $endpoints = $this->endpoints();
        $bodies = [
            $endpoints->approvalStatus($id + 50)[1],
            $endpoints->selection($id + 50, 'inn', [])[1],
            $endpoints->selection($id, 'inn', 'x')[1],
            $endpoints->acknowledge(9999, 1, true)[1],
            $endpoints->resolve(9999, 1, false)[1],
        ];
        $text = (string) json_encode($bodies);
        foreach (['Exception', 'SQL', 'ritmo_plan_approvals', 'Unknown alert', 'No request'] as $word) {
            self::assertStringNotContainsString($word, $text);
        }
    }

    private function endpoints(): ScreenEndpoints
    {
        $world = $this->world;
        $board = new ApprovalBoard($world->properties(), $world->roomTypes(), new RuleSetRepository($world->db, $world->clock), $world->approvals(), new PlanItemRepository($world->db, $world->clock), new WaveHoldRepository($world->db), $world->clock);
        $test = function (string $provider): array {
            $cached = in_array($provider, $this->tested, true);
            $this->tested[] = $provider;

            return ['ok' => true, 'reason' => 'ok', 'text' => 'Connected.', 'tested_at' => '2026-11-13 10:00', 'cached' => $cached];
        };

        return new ScreenEndpoints($board, $this->alerts->service, $test, static fn (string $word): string => ucfirst($word));
    }

    /**
     * A pending request of the property "inn" for both rooms over the given nights.
     */
    private function request(int $nights): int
    {
        $entries = [];
        $statuses = [];
        foreach (['A', 'B'] as $room) {
            foreach (DateRange::nights(Days::date(ScreenWorld::TODAY), $nights) as $night) {
                $entries[] = (new PlanEntry(PlanEntry::ITEM, $room, Days::key($night), ['floor_pct' => -30, 'ceiling_pct' => 50]))->toArray();
                $statuses[$room . '|' . Days::key($night)] = 'would_write';
            }
        }
        $run = $this->world->run(['A', 'B'], $nights, $statuses);
        $now = $this->world->clock->now();

        return $this->world->approvals()->create($run, 'inn', 'plan', str_repeat('a', 64), Days::date(ScreenWorld::TODAY), null, $now, $now->modify('+10 hours'), $entries, $now);
    }

    /**
     * @param list<string> $scope
     */
    private function alert(AlertCode $code, array $scope): int
    {
        $alert = $this->alerts->service->raise(new AlertDraft($code, 'inn', $scope, 'Something to look at.'));
        self::assertNotNull($alert);

        return $alert->id;
    }
}
