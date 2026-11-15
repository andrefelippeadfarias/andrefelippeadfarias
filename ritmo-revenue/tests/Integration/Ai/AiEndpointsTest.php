<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Ai\RequestData;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Days;
use Ritmo\Rest\Ai\AiEndpoints;
use Ritmo\Tests\Unit\Application\Ai\AiWorld;
use Ritmo\Tests\Unit\Domain\Ai\AiFixtures;

/**
 * The answers of the assistant's REST routes against the simulator: a stop of the settings is a
 * 409 with the reason in words, a refused answer or request a 422, a failure of Claude a 502, an
 * unknown object a 404; a draft is created (201) and queued for a person, never applied.
 */
final class AiEndpointsTest extends TestCase
{
    public function testWhileTheAssistantIsOffEveryRouteSaysWhyAndNothingLeaves(): void
    {
        $world = new AiWorld();
        $endpoints = self::endpoints($world);

        self::assertSame([200, false, AiStopped::DISABLED, 'words:disabled'], [$endpoints->status()[0], $endpoints->status()[1]['ready'], $endpoints->status()[1]['reason'], $endpoints->status()[1]['message']]);
        foreach ([$endpoints->draft('inn-1', 'R$ 900 no feriado'), $endpoints->weekly('inn-1'), $endpoints->runReport(5), $endpoints->explainAlert(9), $endpoints->test()] as [$status, $body]) {
            self::assertSame([409, AiStopped::DISABLED, 'words:disabled'], [$status, $body['code'], $body['message']]);
        }
        self::assertSame([], $world->api->sent());
    }

    public function testADraftIsQueuedForAPersonAndAnsweredInWords(): void
    {
        $world = (new AiWorld())->ready();

        [$status, $body] = self::endpoints($world)->draft('inn-1', 'Não vender abaixo de R$ 900 no feriado');

        self::assertSame(201, $status);
        self::assertSame(['order', 'view of ' . $body['draft']['id']], [$body['draft']['kind'], $body['draft']['view']]);
        self::assertCount(1, $world->drafts->pending('inn-1'));
        self::assertSame(AiDraft::ORDER, $world->drafts->pending('inn-1')[0]['draft']->kind);
    }

    public function testRefusedRequestsAndAnswersAre422AndClaudeFailuresAre502(): void
    {
        $world = (new AiWorld())->ready();
        $endpoints = self::endpoints($world);

        [$status, $body] = $endpoints->draft('inn-1', 'O hóspede maria@example.com pediu desconto');
        self::assertSame([422, AiStopped::PRIVACY], [$status, $body['code']]);
        self::assertSame([], $world->api->sent(), 'guest data is never sent');

        [$status, $body] = $endpoints->draft('inn-1', 'mude o modo para autônomo');
        self::assertSame([422, AiStopped::NOT_SUPPORTED], [$status, $body['code']]);

        $world->api->script(['kind' => 'text', 'text' => 'Bookings made: 40.']);
        [$status, $body] = $endpoints->weekly('inn-1');
        self::assertSame([422, AiStopped::UNKNOWN_NUMBER], [$status, $body['code']], '40 is not a number of the report');

        $world->api->script(['kind' => 'error', 'status' => 402, 'type' => 'billing_error']);
        [$status, $body] = $endpoints->runReport(5);
        self::assertSame([502, AiStopped::PROVIDER], [$status, $body['code']]);
        self::assertSame([], $world->drafts->pending('inn-2'));
    }

    public function testReportsAlertsAndTheConnectionTestAnswerText(): void
    {
        $world = (new AiWorld())->ready();
        $endpoints = self::endpoints($world);

        self::assertSame([200, 'Summary of the week. Bookings made: 4.'], [$endpoints->weekly('inn-1')[0], $endpoints->weekly('inn-1')[1]['text']]);
        [$status, $body] = $endpoints->explainAlert(9);
        self::assertSame([200, 'What happened: Night 2026-12-05 is closed without a booking.', 'Open the Alerts screen and review the night.'], [$status, $body['explanation'], $body['action']]);
        [$status, $body] = $endpoints->test();
        self::assertSame([200, ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5']], [$status, array_column($body['models'], 'id')]);
    }

    public function testUnknownObjectsAre404WithASentence(): void
    {
        $endpoints = self::endpoints((new AiWorld())->ready());

        foreach ([$endpoints->draft('nope', 'x'), $endpoints->weekly('nope'), $endpoints->runReport(404), $endpoints->explainAlert(404)] as [$status, $body]) {
            self::assertSame([404, 'not_found'], [$status, $body['code']]);
            self::assertStringStartsWith('missing:', $body['message']);
        }
    }

    private static function endpoints(AiWorld $world): AiEndpoints
    {
        $facts = ['about' => ['report' => 'weekly panel'], 'facts' => [['label' => 'Bookings made', 'value' => '4']]];

        return new AiEndpoints(
            $world->assistant,
            $world->drafts,
            static fn (AiStopped $e): array => ['message' => 'words:' . $e->reason, 'details' => []],
            static function (string $property, string $text): ?array {
                if ($property !== 'inn-1') {
                    return null;
                }

                return RequestData::of(AiWorld::property(), AiWorld::rooms(), RuleSet::fromArray(AiFixtures::rules()), Days::date('2026-11-15'), $text) + ['property' => 'inn-1'];
            },
            static fn (string $property): ?array => $property === 'inn-1' ? $facts : null,
            static fn (int $run): ?array => $run === 5 ? $facts : null,
            static fn (int $alert): ?array => $alert === 9 ? ['alert' => ['code' => 'night_closed_without_booking', 'summary' => 'Night 2026-12-05 is closed without a booking.']] : null,
            static fn (string $id, string $request, string $created, AiDraft $draft, string $property): array => ['id' => $id, 'kind' => $draft->kind, 'view' => 'view of ' . $id],
            static fn (string $what): string => 'missing:' . $what,
            'en',
            7,
        );
    }
}
