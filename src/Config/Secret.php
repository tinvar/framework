<?php

declare(strict_types=1);

namespace Tinvar\Config;

/**
 * A secret a config object holds: a key, a token, a password, a DSN that
 * may carry one. Read with reveal(), where it is used, and nowhere else.
 *
 * The value is not a property. It lives in a map keyed by the object, so
 * nothing that walks an object's properties finds it: var_dump() and
 * print_r() show `[redacted]`, var_export(), `(array)` and
 * get_object_vars() show nothing, json_encode() writes `"[redacted]"`, and
 * serialize() throws -- a secret written to a cache, a queue or a session
 * is a secret kept somewhere nobody meant. It has no __toString(), so it
 * cannot be interpolated into a message or a log line by accident, and a
 * Data field typed string will not take it. The encoder refuses it as it
 * refuses any object that is not Data, in every environment.
 *
 * A stack trace shows it as `Object(Tinvar\Config\Secret)`, and the
 * parameters that carry a revealed value are #[\SensitiveParameter], as
 * every parameter carrying the app key is.
 */
final class Secret implements \JsonSerializable
{
    public const string REDACTED = '[redacted]';

    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A secret is never empty.');
        }

        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    /** The secret itself, for the one call that needs it. */
    public function reveal(): string
    {
        return self::$values[$this] ?? throw new \LogicException('This secret holds nothing.');
    }

    /** Its length in bytes, for a rule such as "32 or more" that needs no more than that. */
    public function length(): int
    {
        return \strlen($this->reveal());
    }

    /**
     * Whether $given is this secret, compared as digests with hash_equals, so
     * neither its contents nor its length leak through how long it takes.
     */
    public function equals(#[\SensitiveParameter] string $given): bool
    {
        return hash_equals(hash('sha256', $this->reveal(), true), hash('sha256', $given, true));
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A secret is never serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A secret is never unserialized.');
    }

    /** Not cloned: a copy would hold nothing, and one secret is one object. */
    private function __clone() {}
}
