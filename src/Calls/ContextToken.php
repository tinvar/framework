<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Render\TokenError;

/**
 * The context a call made from outside a browser may run in, as signed
 * claims: who it is for, the tenant and panel it is held to, the build that
 * issued it, how long it lasts, and the operations it may name.
 *
 * One shape for a tool grant and a service context token, each issued and
 * checked by its own module, told apart by the purpose bound into the MAC
 * (ContextTokens), as a fragment token and a route token are: one place
 * decides what such a token is bound to, and a second kind inherits it.
 *
 *   - `audience`: the service it was issued to; '' for a tool grant, which
 *     is for whoever runs the model's loop as the user.
 *   - `operations`: the actions and queries it may be used for, by name.
 *     Never a wildcard: a token names what it allows.
 *   - `tenant`, `panel`: where it was issued, '' for none. A call is run
 *     there and nowhere wider.
 *   - `user`, `authHash`: who it was issued for, and a keyed hash of them
 *     with their auth epoch (TokenClaims::hashAuth()); '' for none. The id
 *     is carried so the user can be found again and their epoch checked.
 *   - `build`: the build that issued it, which must still be one this
 *     server answers for.
 *   - `singleUse`: whether its id is spent at its first call (ADR 0119);
 *     null in a token issued before tokens said, which a service call
 *     refuses rather than guess.
 *   - `bound`: arguments a call with it must carry, each exactly: the
 *     record it was issued about, so a token for one order says nothing
 *     of another. None unless issued with some; a service call holds a
 *     call to them (binds()).
 */
final readonly class ContextToken
{
    /** The most operations one token may name. */
    public const int MAX_OPERATIONS = 64;

    /** The most arguments one token may be bound to. */
    public const int MAX_BOUND = 16;

    /**
     * @param list<string> $operations
     * @param array<string, string> $bound argument name => the value a call must carry
     */
    public function __construct(
        public string $id,
        public string $audience,
        public array $operations,
        public string $tenant,
        public string $panel,
        public string $user,
        public string $authHash,
        public string $build,
        public int $issuedAt,
        public int $expiresAt,
        public ?bool $singleUse = false,
        public array $bound = [],
    ) {}

    /** Whether it names $operation. */
    public function allows(string $operation): bool
    {
        return \in_array($operation, $this->operations, true);
    }

    /**
     * Whether $arguments carry every argument it is bound to, each exactly:
     * a string, or an integer, equal to the one bound. An argument missing,
     * or of any other shape, does not.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function binds(array $arguments): bool
    {
        foreach ($this->bound as $name => $value) {
            $given = $arguments[$name] ?? null;
            if (\is_int($given)) {
                $given = (string) $given;
            }
            if (!\is_string($given) || !hash_equals($value, $given)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toMap(): array
    {
        $map = [
            'j' => $this->id,
            'a' => $this->audience,
            'o' => $this->operations,
            'y' => $this->tenant,
            'n' => $this->panel,
            'w' => $this->user,
            'u' => $this->authHash,
            'b' => $this->build,
            'i' => $this->issuedAt,
            'e' => $this->expiresAt,
            's' => $this->singleUse ?? false,
        ];
        // Only where there are some: a token bound to nothing reads as
        // every token before tokens could be bound did.
        if ($this->bound !== []) {
            $map['g'] = $this->bound;
        }

        return $map;
    }

    /**
     * Read from a payload whose MAC has verified: each field the type it
     * must be, refused rather than coerced.
     *
     * @param array<string, mixed> $map
     * @throws TokenError
     */
    public static function fromMap(array $map): self
    {
        $operations = $map['o'] ?? null;
        if (!\is_array($operations) || !array_is_list($operations) || $operations === [] || \count($operations) > self::MAX_OPERATIONS) {
            throw new TokenError("field 'o' is not a list of operations");
        }
        foreach ($operations as $operation) {
            if (!\is_string($operation) || $operation === '') {
                throw new TokenError("field 'o' holds something that is not an operation's name");
            }
        }

        return new self(
            id: self::string($map, 'j'),
            audience: self::string($map, 'a'),
            operations: $operations,
            tenant: self::string($map, 'y'),
            panel: self::string($map, 'n'),
            user: self::string($map, 'w'),
            authHash: self::string($map, 'u'),
            build: self::string($map, 'b'),
            issuedAt: self::int($map, 'i'),
            expiresAt: self::int($map, 'e'),
            singleUse: \array_key_exists('s', $map) ? (\is_bool($map['s']) ? $map['s'] : throw new TokenError("field 's' is not a boolean")) : null,
            bound: \array_key_exists('g', $map) ? self::bound($map['g']) : [],
        );
    }

    /**
     * Arguments to bind a token to, each name and value a string -- an
     * integer taken as its digits -- and no more than MAX_BOUND.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException when they are not
     */
    public static function boundOf(mixed $bound): array
    {
        if (!\is_array($bound) || \count($bound) > self::MAX_BOUND) {
            throw new \InvalidArgumentException('A token is bound to at most ' . self::MAX_BOUND . ' arguments.');
        }

        $strings = [];
        foreach ($bound as $name => $value) {
            if (!\is_string($name) || $name === '' || (!\is_string($value) && !\is_int($value))) {
                throw new \InvalidArgumentException('A token is bound to arguments by name, each to a string or an integer.');
            }
            $strings[$name] = (string) $value;
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     * @throws TokenError
     */
    private static function bound(mixed $bound): array
    {
        // As issue() writes it: a map of one to MAX_BOUND names, each to a
        // string. Anything else is refused, not coerced.
        if (!\is_array($bound) || $bound === [] || \count($bound) > self::MAX_BOUND) {
            throw new TokenError("field 'g' is not a map of arguments");
        }
        $strings = [];
        foreach ($bound as $name => $value) {
            if (!\is_string($name) || $name === '' || !\is_string($value)) {
                throw new TokenError("field 'g' is not a map of arguments to strings");
            }
            $strings[$name] = $value;
        }

        return $strings;
    }

    /** @param array<string, mixed> $map */
    private static function string(array $map, string $key): string
    {
        $value = $map[$key] ?? null;
        if (!\is_string($value)) {
            throw new TokenError("field '{$key}' is missing or not a string");
        }

        return $value;
    }

    /** @param array<string, mixed> $map */
    private static function int(array $map, string $key): int
    {
        $value = $map[$key] ?? null;
        if (!\is_int($value) || $value <= 0) {
            throw new TokenError("field '{$key}' is missing or not a time");
        }

        return $value;
    }
}
