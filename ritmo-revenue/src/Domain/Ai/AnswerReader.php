<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * Reads an answer before anything of it is used: the stop reason first (a refusal or a cut answer
 * becomes a clear message, never a text or a draft), then the text or the JSON, then the numbers
 * (a text may only cite numbers of its input) and guest data.
 */
final class AnswerReader
{
    private function __construct()
    {
    }

    /**
     * @throws AiStopped refusal or max_tokens
     */
    public static function complete(AiReply $reply): void
    {
        if ($reply->stopReason === AiReply::STOP_REFUSAL) {
            throw new AiStopped(AiStopped::REFUSAL, 'The model declined to answer this request; nothing was kept.', array_filter(['category' => (string) $reply->refusalCategory], static fn (string $v): bool => $v !== ''));
        }
        if ($reply->stopReason === AiReply::STOP_MAX_TOKENS) {
            throw new AiStopped(AiStopped::MAX_TOKENS, 'The answer was cut before its end; nothing was kept. Try a shorter request.');
        }
        if (!$reply->isComplete()) {
            throw new AiStopped(AiStopped::INVALID_OUTPUT, 'The assistant gave no usable answer; nothing was kept.', ['stop_reason' => mb_substr($reply->stopReason, 0, 40)]);
        }
    }

    /**
     * The text of a report, checked: complete, without guest data, citing only numbers of the input.
     *
     * @throws AiStopped
     */
    public static function text(AiReply $reply, AiRequest $request): string
    {
        self::complete($reply);
        $text = trim($reply->text);
        PrivacyGuard::assertSendableKnowing($request->known, $text);
        self::assertKnownNumbers($text, $request);

        return $text;
    }

    /**
     * The decoded JSON of a structured answer (an object), complete and fitting its schema.
     *
     * @return array<string, mixed>
     *
     * @throws AiStopped
     */
    public static function json(AiReply $reply, AiRequest $request): array
    {
        self::complete($reply);
        $decoded = json_decode($reply->text, true);
        $problems = $request->outputSchema === null ? ['no schema'] : JsonShape::problems($decoded, $request->outputSchema);
        if (!is_array($decoded) || $problems !== []) {
            throw new AiStopped(AiStopped::INVALID_OUTPUT, 'The assistant answered in a shape the plugin does not accept; nothing was kept.', ['problems' => array_slice($problems, 0, 5)]);
        }

        return $decoded;
    }

    /**
     * The explanation of an alert: both texts checked like a report.
     *
     * @return array{explanation: string, action: string}
     *
     * @throws AiStopped
     */
    public static function alert(AiReply $reply, AiRequest $request): array
    {
        $answer = self::json($reply, $request);
        $explanation = trim((string) $answer['explanation']);
        $action = trim((string) $answer['action']);
        if ($explanation === '') {
            throw new AiStopped(AiStopped::INVALID_OUTPUT, 'The assistant gave no usable answer; nothing was kept.');
        }
        PrivacyGuard::assertSendableKnowing($request->known, $explanation, $action);
        self::assertKnownNumbers($explanation . "\n" . $action, $request);

        return ['explanation' => $explanation, 'action' => $action];
    }

    /**
     * @throws AiStopped (unknown_number)
     */
    private static function assertKnownNumbers(string $text, AiRequest $request): void
    {
        $unknown = NumberCheck::unknown($text, $request->user);
        if ($unknown !== []) {
            throw new AiStopped(AiStopped::UNKNOWN_NUMBER, 'The assistant cited a number that is not in the plugin\'s data; the text was thrown away.', ['numbers' => array_slice($unknown, 0, 5)]);
        }
    }
}
