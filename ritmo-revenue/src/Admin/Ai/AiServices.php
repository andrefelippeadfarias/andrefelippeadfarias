<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

use Closure;
use Ritmo\Application\Ai\AiAssistant;
use Ritmo\Application\Ai\AiDraftQueue;
use Ritmo\Application\Ai\AiSettingsStore;
use Ritmo\Application\Ai\DraftHandOff;
use Ritmo\Application\Ai\UsageLedger;
use Ritmo\Connectors\Ai\Claude\AiHttpGuard;
use Ritmo\Connectors\Ai\Claude\ClaudeClient;
use Ritmo\Connectors\Ai\Claude\ClaudeConfig;
use Ritmo\Connectors\Ai\Claude\ClaudeProvider;
use Ritmo\Connectors\Support\BaseUrl;
use Ritmo\Domain\Ai\AiGateway;
use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Ai\RuleCatalogue;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\RequestTarget;
use Ritmo\Infrastructure\Http\WpHttpClient;
use Ritmo\WordPress\Services;

/**
 * The assistant of one request, built from the plugin's services.
 *
 * Its HTTP client is its own: the plugin's read-only guard with an allow-list holding exactly one
 * route, POST /v1/messages of the Claude host (GET /v1/models is a read), and behind it the
 * assistant's guard (AiHttpGuard). The pricing and PMS connectors never get this client, and the
 * Claude host is on no list of theirs. A base URL other than production comes from the constant
 * RITMO_ANTHROPIC_BASE_URL, honoured only while RITMO_DEV is true.
 */
final class AiServices
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

    public function settings(): AiSettingsStore
    {
        return $this->once('settings', fn (): AiSettingsStore => new AiSettingsStore($this->services->store(), $this->services->vault()));
    }

    public function usage(): UsageLedger
    {
        return $this->once('usage', fn (): UsageLedger => new UsageLedger($this->services->store(), $this->services->clock(), wp_timezone_string(), $this->exclusive()));
    }

    public function drafts(): AiDraftQueue
    {
        return $this->once('drafts', fn (): AiDraftQueue => new AiDraftQueue($this->services->store(), $this->services->clock()));
    }

    public function handOff(): DraftHandOff
    {
        return new DraftHandOff(RuleCatalogue::forRules());
    }

    public function assistant(): AiAssistant
    {
        return $this->once('assistant', fn (): AiAssistant => new AiAssistant(
            $this->settings(),
            $this->usage(),
            $this->gateways(),
            $this->services->logger(),
            licensed: fn (): bool => $this->services->licence()->verdict()->allowsAi(),
        ));
    }

    /**
     * Runs a change of the usage while holding the site's lock "ai_usage" (waits up to two
     * seconds for it; a lock that cannot be had does not stop the change).
     *
     * @return Closure(Closure(): mixed): mixed
     */
    private function exclusive(): Closure
    {
        $locks = $this->services->locks();

        return static function (Closure $work) use ($locks): mixed {
            $handle = null;
            for ($try = 0; $try < 40 && $handle === null; $try++) {
                $handle = $locks->acquire('ai_usage', 30);
                if ($handle === null) {
                    usleep(50000);
                }
            }
            try {
                return $work();
            } finally {
                if ($handle !== null) {
                    $locks->release($handle);
                }
            }
        };
    }

    public static function baseUrl(): string
    {
        $override = defined(ClaudeConfig::URL_CONSTANT) ? (string) constant(ClaudeConfig::URL_CONSTANT) : null;

        return BaseUrl::resolve(ClaudeConfig::BASE_URL, $override);
    }

    /**
     * @return Closure(AiSettings, string): AiGateway
     */
    private function gateways(): Closure
    {
        return function (AiSettings $settings, string $key): AiGateway {
            $base = self::baseUrl();
            $messages = RequestTarget::fromUrl($base . ClaudeConfig::MESSAGES_PATH);
            $routes = ReadRouteAllowList::none()->with(ClaudeProvider::ID, 'POST', $messages->host, $messages->path);
            $http = new AiHttpGuard(new GuardedHttpClient(new WpHttpClient(), $routes, $this->services->logger()), $base);
            $config = ClaudeConfig::fromSettings($settings, $key, $base === ClaudeConfig::BASE_URL ? null : $base);

            return new ClaudeProvider(new ClaudeClient($http, $config, $this->services->logger(), null, self::timeLeft($settings->timeoutSeconds)));
        };
    }

    /**
     * The seconds the PHP request has left, after asking for enough time for one answer and two
     * repetitions; null when PHP sets no limit.
     *
     * @return Closure(): ?float
     */
    private static function timeLeft(int $timeout): Closure
    {
        $limit = (int) ini_get('max_execution_time');
        $wanted = min(600, $timeout * 3 + 30);
        if ($limit !== 0 && $limit < $wanted && function_exists('set_time_limit') && set_time_limit($wanted)) {
            $limit = $wanted;
            $started = microtime(true);
        } else {
            $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        }

        return static fn (): ?float => $limit === 0 ? null : max(0.0, $limit - (microtime(true) - $started));
    }

    /**
     * @template T of object
     *
     * @param Closure(): T $make
     *
     * @return T
     */
    private function once(string $name, Closure $make): object
    {
        return $this->made[$name] ??= $make();
    }
}
