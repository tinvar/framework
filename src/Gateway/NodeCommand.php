<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Render\TokenError;

/**
 * One command for one node, as PHP signs it and its agent reads it.
 *
 * Bound to the node it is for -- an agent runs only a command naming its
 * own node -- and to the tenant whose work it is, a moment it was issued
 * and a moment after which no agent runs it. The id is unique, so an agent
 * that remembers the ids it has run until their expiry runs each once,
 * however often the bus relays it.
 *
 *   - `node`: the node's id, as the certificate its environment's CA issued
 *     it names it (NodeAuthority, ADR 0091): letters, digits, '.', '_' and
 *     '-', up to 64.
 *   - `environment`: the build profile that issued it. An agent runs only a
 *     command for its own environment, as a server believes only a node
 *     its own environment's CA enrolled.
 *   - `command`: what to do; lower-case letters, digits, '.', '_' and '-'.
 *   - `arguments`: plain data -- strings, numbers, booleans, null and
 *     arrays of them -- at most MAX_ARGUMENT_BYTES of JSON. Readable by the
 *     bus and the agent, so no secret goes in one.
 */
final readonly class NodeCommand
{
    /** A node's id, as its certificate names it (`urn:tinvar:node:<id>`, NodeAuthority). */
    public const string NODE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    public const string COMMAND = '/^[a-z][a-z0-9._-]{0,63}\z/';

    public const int MAX_ARGUMENT_BYTES = 16_384;

    /** @param array<array-key, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $node,
        public string $environment,
        public string $tenant,
        public string $command,
        public array $arguments,
        public int $issuedAt,
        public int $expiresAt,
    ) {
        if (preg_match(self::NODE, $node) !== 1) {
            throw new \InvalidArgumentException("'{$node}' is not a node's name.");
        }
        if (preg_match(self::COMMAND, $command) !== 1) {
            throw new \InvalidArgumentException("'{$command}' is not a command's name.");
        }
        self::assertPlain($arguments, 0);
        if (\strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > self::MAX_ARGUMENT_BYTES) {
            throw new \LengthException('A command\'s arguments are at most ' . self::MAX_ARGUMENT_BYTES . ' bytes of JSON.');
        }
    }

    /** @return array<string, mixed> */
    public function toMap(): array
    {
        return [
            'j' => $this->id,
            'node' => $this->node,
            'env' => $this->environment,
            'y' => $this->tenant,
            'cmd' => $this->command,
            // {} for none: [] would read back as a list.
            'args' => $this->arguments === [] ? new \stdClass() : $this->arguments,
            'i' => $this->issuedAt,
            'e' => $this->expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $map a payload whose signature has verified
     * @throws TokenError
     */
    public static function fromMap(array $map): self
    {
        $string = static fn (string $key): string => \is_string($map[$key] ?? null) ? $map[$key] : throw new TokenError("field '{$key}' is missing or not a string");
        $time = static fn (string $key): int => \is_int($map[$key] ?? null) && $map[$key] > 0 ? $map[$key] : throw new TokenError("field '{$key}' is missing or not a time");
        $arguments = $map['args'] ?? null;
        if (!\is_array($arguments)) {
            throw new TokenError("field 'args' is not an object");
        }

        try {
            return new self($string('j'), $string('node'), $string('env'), $string('y'), $string('cmd'), $arguments, $time('i'), $time('e'));
        } catch (\InvalidArgumentException|\LengthException|\JsonException $e) {
            throw new TokenError('a field is not what a command carries: ' . $e->getMessage());
        }
    }

    private static function assertPlain(mixed $value, int $depth): void
    {
        if ($depth > 8) {
            throw new \InvalidArgumentException('A command\'s arguments nest at most 8 deep.');
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertPlain($item, $depth + 1);
            }

            return;
        }
        if (($value !== null && !\is_scalar($value)) || (\is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('A command\'s arguments are plain data, and this holds ' . get_debug_type($value) . '.');
        }
    }
}
