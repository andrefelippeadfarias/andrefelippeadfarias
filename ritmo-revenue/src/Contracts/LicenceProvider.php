<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

/**
 * The licensing platform behind the plugin (a test stub until a vendor is chosen).
 *
 * Checked daily and on the licence screen, never inside a pricing run.
 */
interface LicenceProvider
{
    /**
     * Stable id of the provider ("stub").
     */
    public function id(): string;

    /**
     * Asks the provider about a licence key for this site. Never throws: an unreachable
     * provider answers with the status LicenceState::UNREACHABLE.
     *
     * @param string $siteUrl home URL of the site the key is used on
     */
    public function check(string $licenceKey, string $siteUrl): LicenceState;
}
