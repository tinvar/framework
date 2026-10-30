<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Render\TokenError;

/**
 * What an agent reports about a command it ran, as it signs it and PHP
 * reads it once verified (CommandResults, ADR 0119).
 *
 *   - `command`: the id of the command PHP issued, which the result answers.
 *   - `node`, `environment`: the node that ran it and its environment, as
 *     its certificate names them; PHP holds both to the certificate the
 *     result came with and to the command.
 *   - `status`: `succeeded` or `failed`.
 *   - `output`: plain data, at most MAX_OUTPUT_BYTES of JSON: the agent's
 *     own words, which PHP reads as input from a node it trusts for that
 *     node alone.
 *   - `reportedAt`: when the agent signed it.
 *
 * Once verified it also carries the command it answers, as PHP signed it
 * -- its tenant, what it was and its arguments -- read from the outbox,
 * never from the agent.
 */
final readonly class CommandResult
{
    public const string SUCCEEDED = 'succeeded';
    public const string FAILED = 'failed';

    public const int MAX_OUTPUT_BYTES = 16_384;

    /** @param array<array-key, mixed> $output */
    public function __construct(
        public string $command,
        public string $node,
        public string $environment,
        public string $status,
        public array $output,
        public int $reportedAt,
        public ?NodeCommand $answers = null,
    ) {
        if (!\in_array($status, [self::SUCCEEDED, self::FAILED], true)) {
            throw new \InvalidArgumentException("'{$status}' is not a command's outcome: succeeded or failed.");
        }
        if (preg_match(NodeCommand::NODE, $node) !== 1) {
            throw new \InvalidArgumentException("'{$node}' is not a node's name.");
        }
        self::assertPlain($output, 0);
        if (\strlen(json_encode($output, JSON_THROW_ON_ERROR)) > self::MAX_OUTPUT_BYTES) {
            throw new \LengthException('A result\'s output is at most ' . self::MAX_OUTPUT_BYTES . ' bytes of JSON.');
        }
    }

    /** The same result, with the command it answers as PHP signed it. */
    public function answering(NodeCommand $command): self
    {
        return new self($this->command, $this->node, $this->environment, $this->status, $this->output, $this->reportedAt, $command);
    }

    /** @return array<string, mixed> */
    public function toMap(): array
    {
        return [
            'c' => $this->command,
            'node' => $this->node,
            'env' => $this->environment,
            'status' => $this->status,
            'out' => $this->output === [] ? new \stdClass() : $this->output,
            'at' => $this->reportedAt,
        ];
    }

    /**
     * @param array<string, mixed> $map a payload whose signature has verified
     * @throws TokenError
     */
    public static function fromMap(array $map): self
    {
        $string = static fn (string $key): string => \is_string($map[$key] ?? null) ? $map[$key] : throw new TokenError("field '{$key}' is missing or not a string");
        $output = $map['out'] ?? null;
        $at = $map['at'] ?? null;
        if (!\is_array($output)) {
            throw new TokenError("field 'out' is not an object");
        }
        if (!\is_int($at) || $at <= 0) {
            throw new TokenError("field 'at' is missing or not a time");
        }

        try {
            return new self($string('c'), $string('node'), $string('env'), $string('status'), $output, $at);
        } catch (\InvalidArgumentException|\LengthException|\JsonException $e) {
            throw new TokenError('a field is not what a result carries: ' . $e->getMessage());
        }
    }

    private static function assertPlain(mixed $value, int $depth): void
    {
        if ($depth > 8) {
            throw new \InvalidArgumentException('A result\'s output nests at most 8 deep.');
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertPlain($item, $depth + 1);
            }

            return;
        }
        if (($value !== null && !\is_scalar($value)) || (\is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('A result\'s output is plain data, and this holds ' . get_debug_type($value) . '.');
        }
    }
}
