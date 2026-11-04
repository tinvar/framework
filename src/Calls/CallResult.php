<?php

declare(strict_types=1);

namespace Tinvar\Calls;

/**
 * What a call made from outside a browser came to: its value, or why there
 * is none, in a form that can be handed to whoever made it -- a model, as a
 * tool result, or a service, as a response body.
 *
 * Nothing in it is an exception's message. A refusal says what kind it was;
 * a validation failure carries the validator's own messages, which are the
 * application's words for the person filling a form and are written to be
 * read; a failure carries a digest the log can be searched for, as a failed
 * query does. A caller that is a model is untrusted, and the messages are
 * what a user of the same form would see.
 */
final readonly class CallResult
{
    public const string OK = 'ok';

    /** The arguments did not validate: `errors` says which, and why. */
    public const string INVALID = 'invalid';

    /** An #[Authorize] check, or the panel's membership, said no. */
    public const string FORBIDDEN = 'forbidden';

    /** A model id named nothing in scope. */
    public const string NOT_FOUND = 'not_found';

    /**
     * The call itself was refused before anything ran: its token, the
     * context it was made in, or an operation it may not name.
     */
    public const string REFUSED = 'refused';

    /**
     * The application refused it with a 4xx of its own -- a plan it needs
     * (402), a state it is not in (409), a limit (429): `http_status` says
     * which, and `reason` its code. Never its message.
     */
    public const string REJECTED = 'rejected';

    /** It threw; `digest` names it in the log. */
    public const string FAILED = 'failed';

    /**
     * @param array<string, list<string>> $errors field => messages, for INVALID
     * @param array<string, mixed> $flash what an action said for the person who ran it
     */
    private function __construct(
        public string $status,
        public mixed $value = null,
        public array $errors = [],
        public ?string $reason = null,
        public ?string $digest = null,
        public array $flash = [],
        /** For REJECTED: the 4xx the application answered. */
        public ?int $httpStatus = null,
    ) {}

    /**
     * @param mixed $value already encoded: Data, scalars, lists and maps only
     * @param array<string, mixed> $flash
     */
    public static function ok(mixed $value, array $flash = []): self
    {
        return new self(self::OK, $value, flash: $flash);
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors): self
    {
        return new self(self::INVALID, errors: $errors);
    }

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /** @param string $reason a code, never input or an exception's message */
    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, reason: $reason);
    }

    /**
     * @param int $httpStatus a 4xx
     * @param string $code its code: lowercase letters, digits and `_ . -`; anything else is `rejected`
     */
    public static function rejected(int $httpStatus, string $code): self
    {
        if ($httpStatus < 400 || $httpStatus > 499) {
            throw new \InvalidArgumentException('A rejected call carries a 4xx; a 5xx is a failure.');
        }

        return new self(self::REJECTED, reason: preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}\z/', $code) === 1 ? $code : 'rejected', httpStatus: $httpStatus);
    }

    public static function failed(string $digest): self
    {
        return new self(self::FAILED, digest: $digest);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * As JSON-ready data, for a tool result or a response body.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'value' => $this->value,
            'flash' => $this->flash === [] ? null : $this->flash,
            'errors' => $this->errors === [] ? null : $this->errors,
            'reason' => $this->reason,
            'digest' => $this->digest,
            'http_status' => $this->httpStatus,
        ], static fn (mixed $value, string $key): bool => $key === 'status' || $key === 'value' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
