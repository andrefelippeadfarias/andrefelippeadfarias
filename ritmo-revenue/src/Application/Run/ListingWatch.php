<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;

/**
 * Compares the listing values the run read (minimum, base, maximum) with the ones stored for each
 * room. The run already works over the values read (RunContext), so every percentage is over the
 * base the pricing system applies now; a difference is stored as the room's new values (with an
 * event) and, unless it is a listing change the plugin itself wrote, raises
 * listing_changed_manually: a change made by someone else is never reverted (write spec 7.2).
 * Review finding of the M3 correction phase: the base typed at setup was used forever, and a base
 * changed in the pricing system turned every floor of the room (and tonight's guard) wrong.
 */
final class ListingWatch
{
    /** Amounts that differ by less than this are the same listing value (currency rounding). */
    private const TOLERANCE = 0.5;

    public function __construct(
        private readonly RoomTypeRepository $roomTypes,
        private readonly WriteOpRepository $ops,
        private readonly OperationEventRepository $events,
        private readonly AlertService $alerts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<string> the rooms whose listing values changed
     */
    public function inspect(RunContext $context): array
    {
        $live = [];
        foreach ($context->rooms as $room) {
            $live[$room->code] = $room;
        }
        $changed = [];
        foreach ($this->roomTypes->forProperty($context->property->id) as $stored) {
            $read = $live[$stored->code] ?? null;
            $diff = $read === null ? [] : self::differences($stored, $read);
            if ($diff === []) {
                continue;
            }
            $changed[] = $stored->code;
            $this->roomTypes->save($read);
            $before = array_map(static fn (array $pair): float => $pair[0], $diff);
            $after = array_map(static fn (array $pair): float => $pair[1], $diff);
            $ours = $this->ops->listingWritten($context->property->id, $stored->pricingRef, $after);
            $this->events->record(
                $context->property->id,
                OperationEventRepository::LISTING,
                (string) json_encode([$stored->code => $before]),
                (string) json_encode([$stored->code => $after]),
                $ours ? 'Listing values written by the plugin, read back.' : 'Listing values changed in the pricing system: the run uses them.',
                $ours ? 'plugin' : 'system',
                $this->clock->now(),
                $context->runId,
            );
            if (!$ours) {
                $this->alerts->raise(OperationAlerts::listingChanged($context->property->id, $stored->pricingRef, $before, $after, $this->clock->now()), $context->runId);
            }
        }

        return $changed;
    }

    /**
     * @return array<string, array{0: float, 1: float}> field => [stored, read]
     */
    private static function differences(RoomType $stored, RoomType $read): array
    {
        $out = [];
        foreach (['min' => [$stored->min, $read->min], 'base' => [$stored->base, $read->base], 'max' => [$stored->max, $read->max]] as $field => [$was, $now]) {
            if (abs($was - $now) >= self::TOLERANCE) {
                $out[$field] = [$was, $now];
            }
        }

        return $out;
    }
}
