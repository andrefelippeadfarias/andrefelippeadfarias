<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use InvalidArgumentException;

/**
 * The sales ramp of an owner target order on one room night. The night stays on the order and
 * its price climbs one line per booking: from the order's floor (the market base) toward the top
 * of the ramp (the standard floor of the night, or the order's own top), never under the market
 * target of the ramped percentile. Close to the date an unsold night may drop to the order's
 * fallback floor, a night under its pace curve goes down the order's descent steps one at a time,
 * and a night back on its curve climbs back to the floor by at most one step limit at a time.
 *
 * Reference: the escada_ordem branch of pace._plano (piso_com_vendas, piso_atras_da_ordem,
 * degrau_atras_da_ordem, limite_volta_degrau).
 */
final class OrderSalesRamp
{
    /** Labels of the floors the ramp sets. */
    public const FALLBACK_FLOOR = 'order_fallback_floor';
    public const DESCENT_STEP = 'order_descent_step';
    public const RETURN_LIMIT = 'order_return_limit';

    /**
     * @param string         $orderId       the target order of the ramp
     * @param float          $share         share of the ramp sold: the units sold after the order's base, 0 to 1
     * @param float          $top           the top of the ramp: the standard floors and the order's top, the highest
     * @param float          $standardFloor the standard floor of the night: the descent and the return never go
     *                                      under it, nor the fallback ...
     * @param bool           $belowStandard ... unless the order releases a floor below the standard one
     * @param float|null     $fallbackFloor the floor of an unsold night close to the date, when its trigger holds
     * @param list<float>    $descentFloors the floors of the descent steps, highest first
     * @param list<int>      $descentMarks  days before the night at which one more descent step is reached
     * @param float          $reachedSlack  a step counts as reached when its line is within this share of the price
     * @param bool           $weak          the night sells weakly: under the line it goes straight to it
     * @param HardFloor|null $raise         a floor the night never goes under, whatever the ramp did (a premium relation)
     */
    public function __construct(
        public readonly string $orderId,
        public readonly float $share,
        public readonly float $top,
        public readonly float $standardFloor,
        public readonly bool $belowStandard,
        public readonly ?float $fallbackFloor,
        public readonly array $descentFloors,
        public readonly array $descentMarks,
        public readonly float $reachedSlack,
        public readonly bool $weak,
        public readonly ?HardFloor $raise = null,
    ) {
        if ($share < 0.0 || $share > 1.0) {
            throw new InvalidArgumentException('The share sold of the sales ramp must be between 0 and 1.');
        }
        if ($reachedSlack < 0.0 || $reachedSlack >= 1.0) {
            throw new InvalidArgumentException('The slack of a reached step must be between 0 and 1.');
        }
        if (!array_is_list($descentFloors) || !array_is_list($descentMarks)) {
            throw new InvalidArgumentException('The descent steps and marks are lists.');
        }
    }

    public function withRaise(?HardFloor $raise): self
    {
        return new self($this->orderId, $this->share, $this->top, $this->standardFloor, $this->belowStandard, $this->fallbackFloor, $this->descentFloors, $this->descentMarks, $this->reachedSlack, $this->weak, $raise);
    }

    /**
     * The line of the ramp over a floor: from the floor to the top, in proportion to the share
     * sold, never under the market target (reference: pace.piso_com_vendas inside the "reta").
     */
    public function line(float $floor, float $target): float
    {
        return max($target, $floor + (max($this->top, $floor) - $floor) * $this->share);
    }

    /**
     * The floor of the descent step the night reached, or null: one step per mark reached while
     * the night sells under its curve. With the current price, one step at a time: the next step
     * after the last one whose line the price already reached (reference: pace.degrau_atras_da_ordem).
     */
    public function descentFloor(int $daysOut, PaceReading $reading, ?float $current = null, float $target = 0.0): ?float
    {
        if ($this->descentFloors === []) {
            return null;
        }
        $reached = count(array_filter($this->descentMarks, static fn (int $mark): bool => $daysOut <= $mark));
        if ($reached === 0 || $reading->soldPercent >= $reading->expectedPercent) {
            return null;
        }
        $step = min($reached, count($this->descentFloors));
        if ($current !== null) {
            $done = count(array_filter($this->descentFloors, fn (float $floor): bool => $this->line($floor, $target) >= $current * (1 - $this->reachedSlack)));
            $step = min($step, $done + 1);
        }
        $floor = $this->descentFloors[$step - 1];

        return $this->belowStandard ? $floor : max($floor, $this->standardFloor);
    }

    /**
     * Within the window of the descent steps, a night priced under its floor climbs back by at most
     * the step limit of its days out, never under the standard floor; null when that does not apply
     * (reference: pace.limite_volta_degrau).
     */
    public function returnLimit(int $daysOut, float $current, float $floor, float $maxStepPercent): ?float
    {
        if ($this->descentFloors === [] || $this->descentMarks === [] || $daysOut > max($this->descentMarks) || $current >= $floor) {
            return null;
        }
        $limit = max($current * (1 + $maxStepPercent / 100.0), $this->standardFloor);

        return $limit < $floor ? $limit : null;
    }

    /**
     * The floor and the price of the night on the ramp.
     *
     * @param HardFloor $floor          the floor of the night before the ramp (the order's, or a market floor)
     * @param float     $target         the market target of the ramped percentile, in the sent plan
     * @param float     $maxStepPercent the step limit of the night's days out
     */
    public function step(HardFloor $floor, float $target, float $current, int $daysOut, PaceReading $reading, float $maxStepPercent): RampStep
    {
        if ($this->fallbackFloor !== null && $this->fallbackFloor < $floor->amount) {
            $floor = self::floor($this->fallbackFloor, self::FALLBACK_FLOOR, $floor);
        }
        $descent = $this->descentFloor($daysOut, $reading, $current, $target);
        $descended = $descent !== null && $descent < $floor->amount;
        if ($descended) {
            $floor = self::floor($descent, self::DESCENT_STEP, $floor);
        } else {
            $limit = $this->returnLimit($daysOut, $current, $floor->amount, $maxStepPercent);
            $floor = $limit === null ? $floor : self::floor($limit, self::RETURN_LIMIT, $floor);
        }
        $line = $this->line($floor->amount, $target);
        $price = match (true) {
            $line >= $current => min($line, $current * (1 + $maxStepPercent / 100.0)),
            $this->weak || $descended => $line,
            default => $current,
        };
        if ($this->raise !== null && $this->raise->amount > $floor->amount) {
            $floor = self::floor($this->raise->amount, $this->raise->label, $floor);
        }

        return new RampStep($floor, $price, $line);
    }

    private static function floor(float $amount, string $label, HardFloor $before): HardFloor
    {
        return new HardFloor($amount, $label, [...$before->candidates, ['label' => $label, 'amount' => $amount]]);
    }
}
