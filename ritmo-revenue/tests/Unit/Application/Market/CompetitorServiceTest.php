<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Market;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Market\CompetitorInput;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;

final class CompetitorServiceTest extends TestCase
{
    public function testACompetitorIsSavedChangedAndArchivedWithItsEvents(): void
    {
        $world = new MarketWorld();
        $id = $world->competitorService->save(MarketWorld::PROPERTY, null, ['name' => 'Pousada São João', 'tags' => 'peer, Peer', 'factor' => '1,4', 'factor_source' => 'measured', 'rating' => '8.7'], 7);

        $row = $world->competitors->find(MarketWorld::PROPERTY, $id);
        self::assertSame(['pousada-sao-joao', ['peer'], 1.4, 'measured', 8.7, true, false], [$row['code'], $row['tags'], $row['factor'], $row['factor_source'], $row['rating'], $row['active'], $row['is_own']]);

        $world->competitorService->save(MarketWorld::PROPERTY, $id, ['name' => 'Pousada São João', 'code' => 'sao-joao', 'room_label' => 'Room with a tub'], 8);
        self::assertSame(['sao-joao', [], null], [$world->competitors->find(MarketWorld::PROPERTY, $id)['code'], $world->competitors->find(MarketWorld::PROPERTY, $id)['tags'], $world->competitors->find(MarketWorld::PROPERTY, $id)['factor']]);

        $world->competitorService->archive(MarketWorld::PROPERTY, $id, 9);
        self::assertFalse($world->competitors->find(MarketWorld::PROPERTY, $id)['active']);
        self::assertSame([], array_keys($world->competitors->profiles(MarketWorld::PROPERTY)[$id]->tags));

        $events = $world->events->latest(MarketWorld::PROPERTY, [EditorEventRepository::COMPETITOR]);
        self::assertSame(['user:9', 'user:8', 'user:7'], array_column($events, 'actor'));
        self::assertSame(['Competitor archived', 'Competitor changed', 'Competitor added'], array_column($events, 'reason'));
    }

    public function testFieldsAreCheckedAndGuestDataRefused(): void
    {
        $world = new MarketWorld();
        $world->competitorService->save(MarketWorld::PROPERTY, null, ['name' => 'Inn A', 'code' => 'inn-a'], 7);
        $cases = [
            [['name' => ''], MarketRefused::REQUIRED, 'name'],
            [['name' => str_repeat('a', 121)], MarketRefused::TOO_LONG, 'name'],
            [['name' => 'Write to host@example.com'], MarketRefused::PRIVACY, 'name'],
            [['name' => 'Inn B', 'room_label' => 'call +55 11 98765-4321'], MarketRefused::PRIVACY, 'room_label'],
            [['name' => 'Inn B', 'code' => 'Bad Code'], MarketRefused::BAD_CODE, 'code'],
            [['name' => 'Inn A2', 'code' => 'INN-A'], MarketRefused::CODE_TAKEN, 'code'],
            [['name' => 'Inn B', 'tags' => 'Not a tag!'], MarketRefused::BAD_TAG, 'tags'],
            [['name' => 'Inn B', 'factor' => '0'], MarketRefused::BAD_FACTOR, 'factor'],
            [['name' => 'Inn B', 'factor' => '12'], MarketRefused::BAD_FACTOR, 'factor'],
            [['name' => 'Inn B', 'rating' => '11'], MarketRefused::BAD_RATING, 'rating'],
        ];
        foreach ($cases as [$input, $reason, $field]) {
            try {
                $world->competitorService->save(MarketWorld::PROPERTY, null, $input, 7);
                self::fail($reason);
            } catch (MarketRefused $refused) {
                self::assertSame([$reason, $field], [$refused->reason, $refused->field], json_encode($input));
            }
        }
        self::assertCount(1, $world->competitors->forProperty(MarketWorld::PROPERTY));
        self::assertSame('competitor', CompetitorInput::slug('***'));
        self::assertSame('hotel-dos-pinheiros', CompetitorInput::slug('  Hotel dos Pinheiros! '));
    }

    public function testUnknownCompetitorsOfAFileAreCreatedInOneGo(): void
    {
        $world = new MarketWorld();
        $world->competitorService->save(MarketWorld::PROPERTY, null, ['name' => 'Inn A', 'code' => 'inn-a'], 7);

        self::assertSame(2, $world->competitorService->createMissing(MarketWorld::PROPERTY, ['Inn A', 'Inn B', 'Inn C', 'Inn B'], 7));
        self::assertSame(['inn-a', 'inn-b', 'inn-c'], array_column($world->competitors->forProperty(MarketWorld::PROPERTY), 'code'));
    }

    public function testUnknownPropertyAndCompetitorAreRefused(): void
    {
        $world = new MarketWorld();
        foreach ([
            static fn () => $world->competitorService->save('nowhere', null, ['name' => 'X'], 7),
            static fn () => $world->competitorService->save(MarketWorld::PROPERTY, 99, ['name' => 'X'], 7),
            static fn () => $world->competitorService->archive(MarketWorld::PROPERTY, 99, 7),
        ] as $call) {
            try {
                $call();
                self::fail('refused');
            } catch (MarketRefused $refused) {
                self::assertContains($refused->reason, [MarketRefused::PROPERTY_UNKNOWN, MarketRefused::COMPETITOR_UNKNOWN]);
            }
        }
    }

    public function testReferencePricesAreTakenAsNewVersionsAndNeverEdited(): void
    {
        $world = new MarketWorld();
        self::assertNull($world->references->latestVersion(MarketWorld::PROPERTY, 'winter'));
        self::assertSame(1, $world->references->take(MarketWorld::PROPERTY, 'winter', ['room-a' => ['2026-12-04' => 480.0, '2026-12-05' => 500.0]], $world->clock->now()));
        self::assertSame(2, $world->references->take(MarketWorld::PROPERTY, 'winter', ['room-a' => ['2026-12-04' => 490.0]], $world->clock->now()));

        self::assertSame(['2026-12-04' => ['room-a' => 490.0]], $world->references->latest(MarketWorld::PROPERTY, 'winter', Days::date('2026-12-01'), Days::date('2026-12-31')));
        self::assertSame(3, $world->count('ladder_reference'));
    }
}
