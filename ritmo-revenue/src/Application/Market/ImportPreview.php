<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use DateTimeImmutable;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Market\Csv\ParsedRow;

/**
 * What a previewed price file would import: the token of the confirmation, the counts, the
 * range of check-ins, the first valid rows and every problem (at most 200 listed).
 */
final class ImportPreview
{
    public const LISTED_PROBLEMS = 200;

    public const LISTED_ROWS = 50;

    /**
     * @param list<ParsedRow>        $firstRows
     * @param list<MarketCsvProblem> $problems
     * @param list<string>           $unknownCompetitors names as typed in the file, for the "create them" offer
     */
    public function __construct(
        public readonly string $token,
        public readonly int $valid,
        public readonly int $total,
        public readonly int $invalidLines,
        public readonly int $nights,
        public readonly int $competitors,
        public readonly ?DateTimeImmutable $firstCheckIn,
        public readonly ?DateTimeImmutable $lastCheckIn,
        public readonly array $firstRows,
        public readonly array $problems,
        public readonly int $moreProblems,
        public readonly array $unknownCompetitors,
    ) {
    }
}
