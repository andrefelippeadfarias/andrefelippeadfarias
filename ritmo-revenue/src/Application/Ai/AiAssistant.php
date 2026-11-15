<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

use Closure;
use InvalidArgumentException;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\AiGateway;
use Ritmo\Domain\Ai\AiModelInfo;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiReply;
use Ritmo\Domain\Ai\AiRequest;
use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Ai\AnswerReader;
use Ritmo\Domain\Ai\DraftContext;
use Ritmo\Domain\Ai\DraftReader;
use Ritmo\Domain\Ai\PromptBuilder;
use Ritmo\Domain\Ai\RuleCatalogue;

/**
 * The optional assistant: words reports, explains alerts and turns a request into a draft.
 *
 * Nothing leaves the site unless the assistant is on, a key is saved, the month's estimated spend
 * is under the cap and the model is one the key may use: the gateway is not even built otherwise.
 * Every answer is checked before it is used (stop reason, shape, numbers, guest data), and its
 * usage is recorded (tokens and cost, never content). The assistant never writes a price, never
 * creates an order or a rule version, never approves and never touches a mode, the emergency
 * switch or a safety limit: a draft only goes to a person, through the editors.
 */
final class AiAssistant
{
    private readonly PromptBuilder $prompts;

    private readonly DraftReader $drafts;

    /**
     * @param Closure(AiSettings, string): AiGateway $gateways builds the gateway for the settings and the key
     */
    public function __construct(
        private readonly AiSettingsStore $store,
        private readonly UsageLedger $usage,
        private readonly Closure $gateways,
        private readonly Logger $logger,
        ?RuleCatalogue $catalogue = null,
    ) {
        $catalogue ??= RuleCatalogue::forRules();
        $this->prompts = new PromptBuilder($catalogue);
        $this->drafts = new DraftReader($catalogue);
    }

    public function status(): AiStatus
    {
        return new AiStatus($this->store->settings(), $this->store->hasKey(), $this->usage->current());
    }

    /**
     * Lists the models of the key and keeps the list, so the settings only offer models the key
     * may use. Needs the assistant on and a key, like every call.
     *
     * @return list<AiModelInfo>
     *
     * @throws AiStopped
     */
    public function testConnection(): array
    {
        [$settings, $gateway] = $this->open(false);
        try {
            $models = $gateway->models();
        } catch (ConnectorException $e) {
            throw $this->providerFailure($e, 'models');
        }
        $ids = array_values(array_map(static fn (AiModelInfo $m): string => $m->id, $models));
        $this->store->save($settings->with(['available_models' => $ids]));
        $this->logger->info('Claude connection tested.', ['provider' => 'claude', 'models' => count($ids)]);

        return $models;
    }

    /**
     * @param array<string, string|int|float>                     $about
     * @param list<array{label: string, value: string|int|float}> $facts formatted by the reader's locale
     *
     * @throws AiStopped
     */
    public function reportText(AiPurpose $purpose, array $about, array $facts, string $locale): AiText
    {
        $request = $this->prompts->report($purpose, $about, $facts, $locale);
        $reply = $this->ask($request);

        return new AiText(AnswerReader::text($reply, $request), $reply->model, $reply->fallbackUsed);
    }

    /**
     * @param array<string, mixed> $alert code, severity, room, night, summary, context
     *
     * @return array{explanation: string, action: string, model: string, fallback: bool}
     *
     * @throws AiStopped
     */
    public function explainAlert(array $alert, string $locale): array
    {
        $request = $this->prompts->alert($alert, $locale);
        $reply = $this->ask($request);

        return AnswerReader::alert($reply, $request) + ['model' => $reply->model, 'fallback' => $reply->fallbackUsed];
    }

    /**
     * A checked draft from the person's request; nothing is created.
     *
     * @param array<string, mixed> $data the data of the request (RequestData::of)
     *
     * @throws AiStopped
     * @throws InvalidArgumentException for an empty or too long request
     */
    public function draft(string $text, array $data, DraftContext $context, string $locale): AiDraft
    {
        $request = $this->prompts->request($text, $data, $locale);
        $reply = $this->ask($request);

        return $this->drafts->read(AnswerReader::json($reply, $request), $context);
    }

    /**
     * @throws AiStopped
     */
    private function ask(AiRequest $request): AiReply
    {
        [$settings, $gateway] = $this->open(true);
        try {
            $reply = $gateway->send($request);
        } catch (ConnectorException $e) {
            throw $this->providerFailure($e, $request->purpose->value);
        }
        $this->usage->record($request->purpose, $reply, $settings->priceTable());
        $this->logger->info('Claude answered.', array_filter([
            'provider' => 'claude',
            'purpose' => $request->purpose->value,
            'model' => $reply->model,
            'stop_reason' => $reply->stopReason,
            'fallback' => $reply->fallbackUsed ? 'yes' : null,
            'refusal_category' => $reply->refusalCategory,
        ], static fn (mixed $v): bool => $v !== null));

        return $reply;
    }

    /**
     * The settings and the gateway, when the assistant may be asked.
     *
     * @return array{0: AiSettings, 1: AiGateway}
     *
     * @throws AiStopped
     */
    private function open(bool $checkModel): array
    {
        $status = $this->status();
        $blocker = $status->blocker();
        if ($blocker === AiStopped::MODEL_UNAVAILABLE && !$checkModel) {
            $blocker = null;
        }
        if ($blocker !== null) {
            throw new AiStopped($blocker, match ($blocker) {
                AiStopped::DISABLED => 'The assistant is off. Turn it on in its settings.',
                AiStopped::NO_KEY => 'No Claude API key is saved. Add one in the assistant settings.',
                AiStopped::BUDGET => 'The assistant reached its monthly spending cap and stays off until next month, or until the cap is raised.',
                default => 'The saved key cannot use the chosen model. Choose another model in the assistant settings.',
            });
        }
        $key = $this->store->key();
        if ($key === null) {
            throw new AiStopped(AiStopped::NO_KEY, 'The saved Claude API key cannot be read. Save it again in the assistant settings.');
        }

        return [$status->settings, ($this->gateways)($status->settings, $key)];
    }

    private function providerFailure(ConnectorException $e, string $what): AiStopped
    {
        $this->logger->warning('Claude request failed.', array_filter(['provider' => 'claude', 'purpose' => $what, 'http_status' => $e->httpStatus()], static fn (mixed $v): bool => $v !== null));

        return new AiStopped(AiStopped::PROVIDER, $e->getMessage(), array_filter(['http_status' => $e->httpStatus() ?? 0]), $e);
    }
}
