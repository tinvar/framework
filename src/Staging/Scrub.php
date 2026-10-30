<?php

declare(strict_types=1);

namespace Tinvar\Staging;

/**
 * What the staging import does with one column of a production row: the
 * rules a ScrubPlan names for every column it copies (ADR 0090).
 *
 * keep() is the declaration that a column is safe to copy as it is --
 * nothing else copies a value -- and it is refused for a column whose name
 * says it holds a secret unless it says why it is safe anyway. Every other
 * rule writes something production did not hold, or holds sealed under
 * staging's key.
 */
final readonly class Scrub
{
    /**
     * Column names that say they hold a secret or a credential: keep() is
     * refused for them without a reason.
     */
    public const string SECRET_NAMES = '/(pass(word)?|secret|token|api_?key|private|otp|totp|salt|hash|credential|signature|mfa|recovery)/i';

    /** What unusablePassword() writes: a value password_verify() never accepts. */
    public const string UNUSABLE_PASSWORD = '!staging-import';

    /**
     * @param \Closure(mixed, ScrubContext): mixed $apply
     */
    private function __construct(
        public string $kind,
        private \Closure $apply,
        public ?string $because = null,
    ) {}

    /**
     * Copied as it is: declared safe. A column named like a secret needs
     * $because, which says why it is not one -- `token_count`, say.
     */
    public static function keep(?string $because = null): self
    {
        if ($because !== null && trim($because) === '') {
            throw new \InvalidArgumentException('Scrub::keep() was given an empty reason.');
        }

        return new self('keep', static fn (mixed $value): mixed => $value, $because);
    }

    /** Written as NULL. */
    public static function null(): self
    {
        return new self('null', static fn (): mixed => null);
    }

    /** Written as $value, the same in every row. */
    public static function value(string|int|float|bool|null $value): self
    {
        return new self('value', static fn (): mixed => $value);
    }

    /**
     * An address at `staging.invalid` named by a keyed hash of the real one:
     * the same address gives the same pseudonym throughout one import, so a
     * unique index and a join on it still hold, and nobody who did not run
     * the import -- whose key was random and is gone -- can tell whose it was.
     * NULL stays NULL.
     */
    public static function email(): self
    {
        return new self('email', static fn (mixed $value, ScrubContext $context): mixed => $value === null
            ? null
            : 'user-' . $context->pseudonym((string) $value) . '@staging.invalid');
    }

    /** `$prefix` and a keyed hash of the value, as email() makes one: for names, phone numbers, addresses. */
    public static function pseudonym(string $prefix): self
    {
        return new self('pseudonym', static fn (mixed $value, ScrubContext $context): mixed => $value === null
            ? null
            : "{$prefix} " . $context->pseudonym((string) $value));
    }

    /** A password hash nobody can sign in with: staging users are `#[Scenario]`'s. */
    public static function unusablePassword(): self
    {
        return new self('unusable-password', static fn (mixed $value): mixed => $value === null ? null : self::UNUSABLE_PASSWORD);
    }

    /** $bytes fresh random bytes, as hex: for a secret a row must have one of, never production's. */
    public static function random(int $bytes = 16): self
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Scrub::random() makes at least one byte.');
        }

        return new self('random', static fn (mixed $value): mixed => $value === null ? null : bin2hex(random_bytes($bytes)));
    }

    /**
     * A value production sealed with its Encrypter, opened with production's
     * key and sealed again with staging's, for the same context -- the
     * column's `table.column` unless $context says. A value that does not
     * open under production's key stops the import: it is not known to be
     * what the column says it is.
     *
     * Staging can open what this writes, so it copies production's value
     * as surely as keep() does, in a new envelope. A column named like a
     * secret -- a TOTP seed, an API token sealed at rest -- needs $because,
     * as keep() does: a credential is dropped or made afresh, not carried
     * into staging under its key.
     */
    public static function reencrypt(?string $context = null, ?string $because = null): self
    {
        if ($because !== null && trim($because) === '') {
            throw new \InvalidArgumentException('Scrub::reencrypt() was given an empty reason.');
        }

        return new self('reencrypt', static fn (mixed $value, ScrubContext $scrub): mixed => $value === null
            ? null
            : $scrub->reencrypt((string) $value, $context), $because);
    }

    /** Whether this rule writes production's value, or one staging can open to it. */
    public function copiesValue(): bool
    {
        return $this->kind === 'keep' || $this->kind === 'reencrypt';
    }

    /**
     * The application's own rule: $rule is given the production value and
     * the row it is in, and returns what staging holds. It is the
     * application's to make sure that is not production's.
     *
     * @param \Closure(mixed, array<string, mixed>): mixed $rule
     */
    public static function using(\Closure $rule): self
    {
        return new self('using', static fn (mixed $value, ScrubContext $context): mixed => $rule($value, $context->row));
    }

    public function apply(mixed $value, ScrubContext $context): mixed
    {
        return ($this->apply)($value, $context);
    }
}
