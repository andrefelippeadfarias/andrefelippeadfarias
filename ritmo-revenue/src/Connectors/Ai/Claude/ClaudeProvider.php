<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Ai\Claude;

use InvalidArgumentException;
use Ritmo\Contracts\AiProvider;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Ai\AiGateway;
use Ritmo\Domain\Ai\AiModelInfo;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiReply;
use Ritmo\Domain\Ai\AiRequest;
use Throwable;

/**
 * The Claude API with the customer's own key, by plain HTTP through the plugin's HTTP layer (no
 * SDK: nothing in the zip can clash with another plugin). It only asks questions: it never reaches
 * a pricing system or a PMS, and what it answers is text or a draft the plugin checks.
 */
final class ClaudeProvider implements AiProvider, AiGateway
{
    public const ID = 'claude';

    public const LABEL = 'Claude';

    public function __construct(private readonly ClaudeClient $client)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function send(AiRequest $request): AiReply
    {
        return ReplyParser::message($this->client->post(ClaudeConfig::MESSAGES_PATH, MessageBody::body($request, $this->configOf())));
    }

    public function models(): array
    {
        return ReplyParser::models($this->client->get(ClaudeConfig::MODELS_PATH . '?limit=100'));
    }

    /**
     * The plain contract of M1: one text answer to a system prompt and user turns. Assistant
     * turns are refused (a final assistant turn is a prefill these models reject).
     *
     * @throws ConnectorException when no complete text came
     */
    public function complete(string $system, array $messages, int $maxTokens): string
    {
        $user = [];
        foreach ($messages as $message) {
            if (!is_array($message) || ($message['role'] ?? null) !== 'user' || !is_string($message['content'] ?? null)) {
                throw new InvalidArgumentException('The assistant sends user turns only.');
            }
            $user[] = $message['content'];
        }
        $reply = $this->send(new AiRequest(AiPurpose::RunReport, [$system], implode("\n\n", $user), null, $maxTokens));
        if (!$reply->isComplete()) {
            throw new ConnectorException(sprintf('Claude gave no complete answer (stop reason %s).', $reply->stopReason), self::ID, null, false);
        }

        return $reply->text;
    }

    /**
     * Lists the models of the key; never throws.
     */
    public function testConnection(): ConnectionStatus
    {
        try {
            $models = $this->models();
        } catch (Throwable $failure) {
            return new ConnectionStatus(false, $failure instanceof ConnectorException ? $failure->getMessage() : 'The connection to Claude could not be tested.');
        }

        return new ConnectionStatus(true, sprintf('Connected to Claude: the key may use %d models.', count($models)), [
            'models' => implode(',', array_map(static fn (AiModelInfo $m): string => $m->id, $models)),
        ]);
    }

    private function configOf(): ClaudeConfig
    {
        return $this->client->config();
    }
}
