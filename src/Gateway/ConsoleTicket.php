<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Render\TokenError;

/**
 * Leave to open one console, once, soon: what PHP hands a browser to
 * present at the gateway's console edge.
 *
 *   - `target`: the console, in the product's own words ('node:7/vm:12');
 *     the edge opens this one and no other.
 *   - `tenant`, `panel`: where it was issued.
 *   - `user`, `session`: keyed hashes of the user with their auth epoch, and
 *     of the session, as route tokens carry them (TokenClaims). The edge
 *     cannot recompute either -- it holds no key that could -- and needs
 *     to: they say whose console it was in its log, and tell two tickets'
 *     sessions apart without naming either.
 *   - `build`: the build that issued it, and `environment`, its profile: an
 *     edge believes only its own environment's tickets.
 *   - `id`: unique; the edge spends each once.
 *   - `issuedAt`, `expiresAt`: a minute, unless issued for less; five at most.
 */
final readonly class ConsoleTicket
{
    public function __construct(
        public string $id,
        public string $target,
        public string $tenant,
        public string $panel,
        public string $user,
        public string $session,
        public string $build,
        public string $environment,
        public int $issuedAt,
        public int $expiresAt,
    ) {
        if (preg_match('/^[\x21-\x7E]{1,256}\z/', $target) !== 1) {
            throw new \InvalidArgumentException('A console target is printable ASCII, up to 256.');
        }
    }

    /** @return array<string, mixed> */
    public function toMap(): array
    {
        return [
            'j' => $this->id,
            't' => $this->target,
            'y' => $this->tenant,
            'n' => $this->panel,
            'u' => $this->user,
            's' => $this->session,
            'b' => $this->build,
            'env' => $this->environment,
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

        try {
            return new self($string('j'), $string('t'), $string('y'), $string('n'), $string('u'), $string('s'), $string('b'), $string('env'), $time('i'), $time('e'));
        } catch (\InvalidArgumentException $e) {
            throw new TokenError($e->getMessage());
        }
    }
}
