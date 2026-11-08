<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * What a session's sign-in proved (ADR 0184): the factors, kept with its row
 * sealed under the app key, and read back with the session
 * (SessionState::factors()). None for a session signed out, half-way
 * through signing in, or made before factors were kept.
 *
 * Multi-factor is a passkey -- verified with its user, as the kit always
 * asks -- or a first factor and a code: what an app requires before a panel
 * that a stolen password must not open (RequireMultiFactor). A recovery
 * code counts, as the kit's own second-factor step takes one; an app that
 * would not count it asks has().
 */
final readonly class SessionFactors
{
    /** The longest a session's factors are written: each one, once. */
    public const int MAX_LENGTH = 64;

    /** @param list<SignInFactor> $factors each once, in SignInFactor's order */
    private function __construct(private array $factors) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(SignInFactor ...$factors): self
    {
        return self::none()->with(...$factors);
    }

    /** These factors and $more. */
    public function with(SignInFactor ...$more): self
    {
        $all = [...$this->factors, ...$more];

        return new self(array_values(array_filter(
            SignInFactor::cases(),
            static fn (SignInFactor $case): bool => \in_array($case, $all, true),
        )));
    }

    public function has(SignInFactor $factor): bool
    {
        return \in_array($factor, $this->factors, true);
    }

    /** Whether nothing is recorded: signed out, or a session from before factors were kept. */
    public function isEmpty(): bool
    {
        return $this->factors === [];
    }

    /**
     * Whether the sign-in proved more than one factor: a passkey, or a
     * password or an emailed link and a code.
     */
    public function multiFactor(): bool
    {
        if ($this->has(SignInFactor::Passkey)) {
            return true;
        }
        $first = $this->has(SignInFactor::Password) || $this->has(SignInFactor::EmailLink);
        $second = $this->has(SignInFactor::AuthenticatorCode) || $this->has(SignInFactor::RecoveryCode);

        return $first && $second;
    }

    /** @return list<SignInFactor> */
    public function all(): array
    {
        return $this->factors;
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_map(static fn (SignInFactor $f): string => $f->value, $this->factors);
    }

    /** As a row and a handoff code keep them: `password+authenticator-code`, '' for none. */
    public function encode(): string
    {
        return implode('+', $this->values());
    }

    /**
     * Factors as encode() wrote them. A name no factor has -- one a later
     * release wrote, say -- is passed over, never guessed at: fewer factors
     * are what an unknown one can mean.
     */
    public static function decode(string $encoded): self
    {
        if ($encoded === '' || \strlen($encoded) > self::MAX_LENGTH) {
            return self::none();
        }

        return self::of(...array_values(array_filter(array_map(SignInFactor::tryFrom(...), explode('+', $encoded)))));
    }
}
