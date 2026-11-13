<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

/**
 * Where each person's rules draft is kept between requests (user meta in WordPress).
 */
interface RuleDraftStorage
{
    public function get(int $userId): ?RuleDraft;

    public function put(int $userId, RuleDraft $draft): void;

    public function clear(int $userId): void;
}
