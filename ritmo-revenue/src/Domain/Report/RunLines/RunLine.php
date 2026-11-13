<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\RunLines;

use InvalidArgumentException;

/**
 * One line of a run report as it is stored: a code and its parameters (counts and reason codes
 * only, so no guest field can ever ride along). Rendered in the reader's language at display time.
 */
final class RunLine
{
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param array<string, int|string> $params
     */
    private function __construct(public readonly RunLineCode $code, public readonly array $params)
    {
    }

    /**
     * @param array<string, mixed> $params exactly the parameters the code takes
     *
     * @throws InvalidArgumentException on a missing, extra or malformed parameter
     */
    public static function of(RunLineCode $code, array $params = []): self
    {
        $expected = $code->params();
        if (array_diff_key($params, $expected) !== [] || array_diff_key($expected, $params) !== []) {
            throw new InvalidArgumentException(sprintf('The report line "%s" takes exactly: %s.', $code->value, implode(', ', array_keys($expected)) ?: 'nothing'));
        }
        $clean = [];
        foreach ($expected as $name => $type) {
            $clean[$name] = self::param($code, $name, $type, $params[$name]);
        }

        return new self($code, $clean);
    }

    /**
     * A stored line read back; null for an unknown code or malformed parameters (shown as an
     * unknown line, never with its raw parameters).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $code = is_string($data['code'] ?? null) ? RunLineCode::tryFrom($data['code']) : null;
        if ($code === null || !is_array($data['params'] ?? [])) {
            return null;
        }
        try {
            return self::of($code, $data['params'] ?? []);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{code: string, params: array<string, int|string>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'params' => $this->params];
    }

    private static function param(RunLineCode $code, string $name, string $type, mixed $value): int|string
    {
        if ($type === 'int' && is_int($value) && $value >= 0) {
            return $value;
        }
        if ($type === 'code' && is_string($value) && preg_match(self::CODE_PATTERN, $value) === 1) {
            return $value;
        }
        throw new InvalidArgumentException(sprintf('The parameter "%s" of the report line "%s" must be %s.', $name, $code->value, $type === 'int' ? 'a count' : 'a reason code'));
    }
}
