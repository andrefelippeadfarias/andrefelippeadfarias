<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * Finds a cycle in a directed graph of rooms (an edge X -> Y means "the price of Y depends on
 * the price of X"). Depth-first, in sorted order, so the walk reported is deterministic.
 */
final class CycleFinder
{
    private const OPEN = 1;
    private const DONE = 2;

    /** @var array<string, int> */
    private array $state = [];

    /** @var list<string> */
    private array $stack = [];

    /**
     * @param array<string, list<string>> $edges room => rooms that depend on it
     */
    private function __construct(private readonly array $edges)
    {
    }

    /**
     * The closed walk of the first cycle found (["A", "B", "A"]), or null.
     *
     * @param array<string, list<string>> $edges
     *
     * @return list<string>|null
     */
    public static function find(array $edges): ?array
    {
        $finder = new self($edges);
        $nodes = array_keys($edges);
        sort($nodes, SORT_STRING);
        foreach ($nodes as $node) {
            if (!isset($finder->state[$node])) {
                $cycle = $finder->visit((string) $node);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function visit(string $node): ?array
    {
        $this->state[$node] = self::OPEN;
        $this->stack[] = $node;
        $next = $this->edges[$node] ?? [];
        sort($next, SORT_STRING);
        foreach ($next as $to) {
            if (($this->state[$to] ?? 0) === self::OPEN) {
                $start = (int) array_search($to, $this->stack, true);

                return [...array_slice($this->stack, $start), $to];
            }
            if (!isset($this->state[$to])) {
                $cycle = $this->visit($to);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }
        array_pop($this->stack);
        $this->state[$node] = self::DONE;

        return null;
    }
}
