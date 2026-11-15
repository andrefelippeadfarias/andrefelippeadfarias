<?php

declare(strict_types=1);

namespace Ritmo\Admin\Licence;

use Closure;
use Ritmo\Application\Licence\LicenceService;
use Ritmo\Application\Update\UpdateChecker;
use Ritmo\Infrastructure\Update\UpdateConstants;
use Ritmo\WordPress\Services;

/**
 * The licence and update objects of one request, over the site's services: the licence service
 * (WordPress\Services::licence(): vault, settings, clock, log; the provider through an HTTP client
 * bound to the licence server's host) and the update checker (an HTTP client bound to the update
 * server's host). Built on first use.
 */
final class LicenceServices
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $made = [];

    private function __construct(private readonly Services $services)
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(Services::instance());
    }

    public function services(): Services
    {
        return $this->services;
    }

    /**
     * The site's licence service (WordPress\Services::licence(): the same object the runs read).
     */
    public function licence(): LicenceService
    {
        return $this->services->licence();
    }

    public function updates(): UpdateChecker
    {
        return $this->once('updates', function (): UpdateChecker {
            $settings = UpdateConstants::settings(RITMO_VERSION, plugin_basename(RITMO_FILE));

            return new UpdateChecker(
                $this->services->updateHttp($settings),
                $this->services->store(),
                $this->services->clock(),
                $this->services->logger(),
                $settings,
            );
        });
    }

    /**
     * @template T of object
     *
     * @param Closure(): T $make
     *
     * @return T
     */
    private function once(string $key, Closure $make): object
    {
        return $this->made[$key] ??= $make();
    }
}
