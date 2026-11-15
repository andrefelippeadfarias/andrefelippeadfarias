<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use RuntimeException;

/**
 * The door to the model for the assistant: one request and its answer, and the models of the
 * key. Implemented by the Claude connector over the plugin's HTTP layer (Connectors\Ai\Claude).
 */
interface AiGateway
{
    /**
     * @throws RuntimeException when no usable answer came (the connector's ConnectorException: status, timeout, malformed body)
     */
    public function send(AiRequest $request): AiReply;

    /**
     * @return list<AiModelInfo>
     *
     * @throws RuntimeException
     */
    public function models(): array;
}
