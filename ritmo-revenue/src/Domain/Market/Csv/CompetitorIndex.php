<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

/**
 * Finds the competitor a file's "competitor" cell names: by code, then by reference, then by the
 * exact name (case-insensitive). Only active competitors of the property are listed.
 */
final class CompetitorIndex
{
    /** @var array<string, int> */
    private array $byCode = [];

    /** @var array<string, int> */
    private array $byReference = [];

    /** @var array<string, int> */
    private array $byName = [];

    /**
     * @param list<array{id: int, code: string, reference: string, name: string}> $competitors active ones
     */
    public function __construct(array $competitors)
    {
        foreach ($competitors as $c) {
            $this->byCode[mb_strtolower($c['code'])] ??= $c['id'];
            if ($c['reference'] !== '') {
                $this->byReference[mb_strtolower($c['reference'])] ??= $c['id'];
            }
            $this->byName[mb_strtolower($c['name'])] ??= $c['id'];
        }
    }

    public function find(string $text): ?int
    {
        $key = mb_strtolower(trim($text));

        return $this->byCode[$key] ?? $this->byReference[$key] ?? $this->byName[$key] ?? null;
    }
}
