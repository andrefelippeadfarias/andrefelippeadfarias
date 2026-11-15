<?php

declare(strict_types=1);

namespace Ritmo\Admin\Licence;

use Closure;
use Ritmo\Application\Licence\LicenceService;
use Ritmo\Application\Licence\LicenceSettings;
use Ritmo\Application\Licence\ProviderChoice;
use Ritmo\Application\Update\UpdateChecker;
use Ritmo\Infrastructure\Http\WpHttpClient;
use Ritmo\Infrastructure\Update\UpdateConstants;
use Ritmo\WordPress\Services;

/**
 * The licence and update objects of one request, over the site's services: the licence service
 * (vault, settings, clock, log; the provider through the WordPress HTTP API) and the update
 * checker. Built on first use.
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

    public function licence(): LicenceService
    {
        return $this->once('licence', function (): LicenceService {
            $settings = LicenceSettings::fromConstants(home_url(), RITMO_VERSION);

            return new LicenceService(
                $this->services->vault(),
                $this->services->store(),
                $this->services->clock(),
                $this->services->logger(),
                new ProviderChoice($settings, new WpHttpClient('RitmoRevenue/' . RITMO_VERSION), $this->services->clock()),
                $settings,
            );
        });
    }

    public function updates(): UpdateChecker
    {
        return $this->once('updates', fn (): UpdateChecker => new UpdateChecker(
            new WpHttpClient('RitmoRevenue/' . RITMO_VERSION),
            $this->services->store(),
            $this->services->clock(),
            $this->services->logger(),
            UpdateConstants::settings(RITMO_VERSION, plugin_basename(RITMO_FILE)),
        ));
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
