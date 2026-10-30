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
 */
final readonly class ContextToken
{
    /** The most operations one token may name. */
    public const int MAX_OPERATIONS = 64;

    /**
     * @param list<string> $operations
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
    ) {}

    /** Whether it names $operation. */
    public function allows(string $operation): bool
    {
        return \in_array($operation, $this->operations, true);
    }

    /** @return array<string, mixed> */
    public function toMap(): array
    {
        return [
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
        );
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
