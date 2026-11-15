<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Licence;

use Ritmo\Contracts\LicenceProvider;
use Ritmo\Contracts\LicenceState;

/**
 * A licence provider that also activates a key on a site and frees it again. check() is the
 * validation of an activated key.
 */
interface LicenceServer extends LicenceProvider
{
    /**
     * Binds the key to this site (the first time) and answers like check(). Never throws.
     */
    public function activate(string $licenceKey, string $siteUrl): LicenceState;

    /**
     * Frees the key from this site. Never throws.
     *
     * @return bool true when the provider confirmed it
     */
    public function deactivate(string $licenceKey, string $siteUrl): bool;
}
