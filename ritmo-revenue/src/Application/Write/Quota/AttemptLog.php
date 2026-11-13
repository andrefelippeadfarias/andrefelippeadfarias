<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The recording rules of recalculation attempts: an attempt recorded twice counts once. With a
 * response id on both sides the id decides (the same id is the same attempt, another id is a new
 * attempt even in the same second); otherwise the listing and the whole second decide. The same
 * attempt with another outcome, or one id on two listings, is refused; so is an instant more than
 * the clock slack ahead of now, or a time written without a timezone.
 * Reference: cota_recalculo.registrar and cota_recalculo.instante (golden cota_recalculo.registrar.json).
 */
final class AttemptLog
{
    public function __construct(private readonly int $futureSlackSeconds = 300)
    {
    }

    /**
     * @param list<RecalcAttempt> $recorded the attempts already recorded
     *
     * @return RecalcAttempt|null the attempt to store; null when it is already recorded
     *
     * @throws AttemptRefused
     */
    public function accept(array $recorded, RecalcAttempt $attempt, DateTimeImmutable $now): ?RecalcAttempt
    {
        if ($attempt->at > $now->modify(sprintf('+%d seconds', $this->futureSlackSeconds))) {
            throw new AttemptRefused('The attempt is in the future: check its date.');
        }
        foreach ($recorded as $other) {
            if (!$this->same($other, $attempt)) {
                continue;
            }
            if ($other->outcome !== $attempt->outcome) {
                throw new AttemptRefused('The same attempt is already recorded with another outcome.');
            }

            return null;
        }

        return $attempt;
    }

    /**
     * An instant written with its timezone, in UTC.
     *
     * @throws AttemptRefused on an invalid text or one without a timezone (it would be hours off)
     */
    public static function instant(string $text): DateTimeImmutable
    {
        $text = trim($text);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $text) !== 1) {
            throw new AttemptRefused('An instant needs a date, a time and a timezone (Z or an offset).');
        }
        try {
            return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new AttemptRefused('Invalid instant.');
        }
    }

    /**
     * @throws AttemptRefused when one response id names two listings
     */
    private function same(RecalcAttempt $a, RecalcAttempt $b): bool
    {
        if ($a->responseId !== null && $b->responseId !== null) {
            if ($a->responseId === $b->responseId && $a->listing !== $b->listing) {
                throw new AttemptRefused('This response id is already recorded for another listing.');
            }

            return $a->responseId === $b->responseId;
        }

        return $a->listing === $b->listing && $a->at == $b->at;
    }
}
