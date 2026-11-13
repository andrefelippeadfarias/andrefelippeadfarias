<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Dashboard;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Dashboard\Todo\TodoGenerator;
use Ritmo\Application\Dashboard\Todo\TodoItem;
use Ritmo\Application\Dashboard\Todo\TodoService;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\UndoAction;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Tests\Unit\Application\Grid\ScreenWorld;

final class TodoTest extends TestCase
{
    private ScreenWorld $world;

    protected function setUp(): void
    {
        $this->world = new ScreenWorld();
        $this->world->property();
    }

    public function testPeopleAddCloseAndReopenItems(): void
    {
        $service = new TodoService($this->world->todos(), $this->world->clock);
        $id = $service->add('inn', 'Fix the sign', 'Before the weekend', Days::date('2026-11-20'), 7);

        $open = $service->list('inn')['open'];
        self::assertCount(1, $open);
        self::assertSame('Before the weekend', $open[0]->details);
        self::assertSame(TodoItem::PERSON, $open[0]->source);
        self::assertFalse($open[0]->isDue(Days::date('2026-11-13')));

        $service->close('inn', $id, 7);
        self::assertSame([], $service->list('inn')['open']);
        self::assertSame(7, $service->list('inn')['done'][0]->closedBy);
        $service->reopen('inn', $id, 7);
        self::assertCount(1, $service->list('inn')['open']);
    }

    public function testAnItemOfAnotherPropertyCannotBeClosed(): void
    {
        $service = new TodoService($this->world->todos(), $this->world->clock);
        $id = $service->add('inn', 'Fix the sign', '', null, 7);

        $this->expectException(\OutOfBoundsException::class);
        $service->close('other', $id, 7);
    }

    public function testATooLongTitleIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TodoService($this->world->todos(), $this->world->clock))->add('inn', str_repeat('a', 191), '', null, 1);
    }

    public function testSyncNowIsCreatedOncePerRoomAndDayEvenAfterItWasDone(): void
    {
        $generator = new TodoGenerator($this->world->todos(), $this->world->settings, $this->world->clock);
        $change = $this->change('A', '2026-11-13');
        $generator->refresh('inn', 'America/Sao_Paulo', RuleSet::defaults(), [$change, $this->change('A', '2026-11-14'), $this->change('B', '2026-11-25')]);

        $syncs = array_values(array_filter($this->world->todos()->open('inn'), static fn (TodoItem $t): bool => $t->kind === TodoGenerator::SYNC_NOW));
        self::assertCount(1, $syncs);
        self::assertSame('A', $syncs[0]->params['room'] ?? null);

        $this->world->todos()->setStatus((int) $syncs[0]->id, TodoItem::DONE, 1, $this->world->clock->now());
        $generator->refresh('inn', 'America/Sao_Paulo', RuleSet::defaults(), [$change]);
        self::assertSame([], array_values(array_filter($this->world->todos()->open('inn'), static fn (TodoItem $t): bool => $t->kind === TodoGenerator::SYNC_NOW)));
    }

    public function testConditionItemsOpenWhileTheirConditionHoldsAndCloseThemselves(): void
    {
        $generator = new TodoGenerator($this->world->todos(), $this->world->settings, $this->world->clock);
        $generator->refresh('inn', 'America/Sao_Paulo', RuleSet::defaults(), []);
        $kinds = array_map(static fn (TodoItem $t): string => $t->kind, $this->world->todos()->open('inn'));
        self::assertContains(TodoGenerator::SAFETY_CONFIRM, $kinds);
        self::assertContains(TodoGenerator::CONFIRM_ORIGINS, $kinds);
        self::assertCount(2, array_keys($kinds, TodoGenerator::CONNECTION_TEST, true));

        $this->world->settings->set('connection_test_beds24', ['ok' => true, 'at' => $this->world->clock->now()->format(DATE_ATOM)]);
        $this->world->settings->set('setup', ['safety_confirmed_at' => '2026-11-13T10:00:00+00:00']);
        $generator->refresh('inn', 'America/Sao_Paulo', RuleSet::defaults(), []);
        $kinds = array_map(static fn (TodoItem $t): string => $t->kind, $this->world->todos()->open('inn'));
        self::assertNotContains(TodoGenerator::SAFETY_CONFIRM, $kinds);
        self::assertCount(1, array_keys($kinds, TodoGenerator::CONNECTION_TEST, true), 'only the provider never tested');
        self::assertSame(count($kinds), $this->world->todos()->countDue('inn', Days::date('2026-11-13')));
    }

    private function change(string $room, string $night): ChangeRecord
    {
        return new ChangeRecord('inn', Provider::Pricing, $room, Days::date($night), 'floor_pct', 10, -5, UndoAction::fromArray(['kind' => 'write_fields', 'fields' => ['floor_pct' => 10]]), 'autonomous', $this->world->clock->now()->modify('-1 hour'), 500.0, 450.0);
    }
}
