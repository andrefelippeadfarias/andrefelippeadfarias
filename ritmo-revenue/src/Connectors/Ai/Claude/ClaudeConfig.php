<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Ai\Claude;

use InvalidArgumentException;
use LogicException;
use Ritmo\Connectors\Support\BaseUrl;
use Ritmo\Domain\Ai\AiEffort;
use Ritmo\Domain\Ai\AiModel;
use Ritmo\Domain\Ai\AiSettings;
use SensitiveParameter;

/**
 * What the Claude connector needs for one site: the customer's own key, the model, the effort,
 * whether the server-side fallback is on and the wait for one answer. The API version and the
 * paths are fixed. The key never appears in a dump of the object.
 */
final class ClaudeConfig
{
    public const BASE_URL = 'https://api.anthropic.com';

    public const API_VERSION = '2026-10-21';

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public const MESSAGES_PATH = '/v1/messages';

    public const MODELS_PATH = '/v1/models';

    public const URL_CONSTANT = 'RITMO_ANTHROPIC_BASE_URL';

    private const KEY_MAX = 400;

    public readonly string $baseUrl;

    /**
     * @param string|null $baseUrl replacement for the API base URL; accepted only while RITMO_DEV is true
     *
     * @throws InvalidArgumentException on an empty or malformed key, an unknown model or a base URL that may not be used
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $apiKey,
        public readonly string $model = AiModel::DEFAULT,
        public readonly AiEffort $effort = AiEffort::DEFAULT,
        public readonly bool $fallback = true,
        public readonly int $timeoutSeconds = AiSettings::TIMEOUT_DEFAULT,
        ?string $baseUrl = null,
    ) {
        if ($apiKey === '' || strlen($apiKey) > self::KEY_MAX || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1) {
            throw new InvalidArgumentException('A Claude API key cannot be empty or contain spaces or control characters.');
        }
        if (!AiModel::isWellFormed($model)) {
            throw new InvalidArgumentException('The model is a Claude model id, such as claude-opus-5-5.');
        }
        if ($timeoutSeconds < AiSettings::TIMEOUT_MIN || $timeoutSeconds > AiSettings::TIMEOUT_MAX) {
            throw new InvalidArgumentException(sprintf('The wait for an answer is from %d to %d seconds.', AiSettings::TIMEOUT_MIN, AiSettings::TIMEOUT_MAX));
        }
        $this->baseUrl = BaseUrl::resolve(self::BASE_URL, $baseUrl);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromSettings(AiSettings $settings, #[SensitiveParameter] string $apiKey, ?string $baseUrl = null): self
    {
        return new self($apiKey, $settings->model, $settings->effort, $settings->fallback, $settings->timeoutSeconds, $baseUrl);
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['api_key' => '(hidden)', 'model' => $this->model, 'effort' => $this->effort->value, 'fallback' => $this->fallback, 'timeout_seconds' => $this->timeoutSeconds, 'base_url' => $this->baseUrl];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException always: a credential is never written to a cache, a session or a log
     */
    public function __serialize(): array
    {
        throw new LogicException('Claude credentials cannot be serialised.');
    }
}
