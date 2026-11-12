<?php

declare(strict_types=1);

namespace Ritmo\Application;

use Ritmo\Contracts\KeyValueStore;

/**
 * The general emergency switch. While it is engaged no run starts and a running run stops before
 * its next stage. It is engaged by the site option or, without any way to release it from the
 * admin, by the constant RITMO_KILL_SWITCH in wp-config.php.
 */
final class EmergencySwitch
{
    /** Key of the option in the plugin's store ("ritmo_kill_switch" in WordPress). */
    public const KEY = 'kill_switch';

    /** Name of the constant that engages the switch for the whole site. */
    public const CONSTANT = 'RITMO_KILL_SWITCH';

    public function __construct(private readonly KeyValueStore $store, private readonly bool $engagedByConstant = false)
    {
    }

    /**
     * Reads the constant the way the composition root passes it: defined and truthy.
     */
    public static function constantIsSet(): bool
    {
        return defined(self::CONSTANT) && (bool) constant(self::CONSTANT);
    }

    public function isEngaged(): bool
    {
        return $this->engagedByConstant || $this->store->get(self::KEY, false) === true;
    }

    public function isEngagedByConstant(): bool
    {
        return $this->engagedByConstant;
    }

    public function engage(): void
    {
        $this->store->set(self::KEY, true);
    }

    /**
     * Releases the option. The constant, when set, keeps the switch engaged.
     */
    public function release(): void
    {
        $this->store->delete(self::KEY);
    }
}
