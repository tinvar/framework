<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * A declaration problem, not a denial.
 *
 * A denial is a 403. This is raised when the framework cannot evaluate a check
 * at all -- a missing policy, a missing ability. It fails the request rather
 * than defaulting to allow or to a silent deny that looks like a policy
 * decision.
 */
final class AuthorizationException extends \RuntimeException
{
    /**
     * A declaration bug is a server error, not a denial.
     *
     * Carrying the status means an error layer can classify it without
     * matching on the message -- which must not reach a client, since these
     * carry policy and class names.
     */
    public int $status {
        get => 500;
    }

    /** @param list<string> $matches */
    public static function ambiguousPolicy(string $subject, array $matches): self
    {
        return new self(
            "Policies for " . implode(' and ', $matches) . " both apply to {$subject}, and neither is more "
            . 'specific. Register one for ' . $subject . ' so the choice is not left to registration order.',
        );
    }

    public static function notPublic(string $policy, string $method): self
    {
        return new self(
            "{$policy}::{$method}() is not public, so the gate cannot call it. "
            . 'A policy ability, and before(), must be public.',
        );
    }

    public static function notBoolean(string $policy, string $method, string $got): self
    {
        return new self(
            "{$policy}::{$method}() returned {$got}; a policy must answer with a bool. "
            . 'A truthy value is not a decision, and treating one as an allow is how a policy that '
            . 'returns a reason object becomes a permit.',
        );
    }

    public static function noPolicy(string $subject): self
    {
        return new self(
            "No policy is registered for {$subject}. Every #[Authorize] needs one; "
            . 'register it, or declare #[NoAuthorization(reason: ...)] on the entry point.',
        );
    }

    public static function noAbility(string $policy, string $ability): self
    {
        return new self("{$policy} has no '{$ability}' method, and no before() hook answered it.");
    }

    public static function unresolvedSubject(string $ability, string $argument, string $got): self
    {
        return new self(
            "#[Authorize('{$ability}', '{$argument}')] needs a resolved model for '{$argument}', got {$got}. "
            . 'Model arguments are resolved and scoped before authorization; a raw identifier is not a subject.',
        );
    }

    public static function badSubject(string $ability): self
    {
        return new self(
            "#[Authorize('{$ability}')] has no subject to check. Name the model-typed input it covers, "
            . 'or the class, as in Authorize(\'viewAny\', Rack::class).',
        );
    }
}
