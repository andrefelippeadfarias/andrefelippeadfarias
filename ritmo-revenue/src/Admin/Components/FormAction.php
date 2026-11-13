<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Closure;

/**
 * A form action of admin-post.php (admin spec 4.1, 6.1): the capability checked first, then the
 * nonce (per object for destructive actions on one object: "<action>:<id>"), then the handler,
 * which returns the arguments of the screen to redirect to.
 */
final class FormAction
{
    /**
     * @param Closure(Input): array<string, string|int> $handler
     */
    public function __construct(
        public readonly string $action,
        public readonly string $capability,
        public readonly string $page,
        public readonly Closure $handler,
        public readonly bool $perObjectNonce = false,
        public readonly string $objectField = '',
        public readonly bool $download = false,
    ) {
    }

    /**
     * The nonce action of a form of this action for one object.
     */
    public function nonceAction(string $objectId = ''): string
    {
        return $this->perObjectNonce ? $this->action . ':' . $objectId : $this->action;
    }
}
