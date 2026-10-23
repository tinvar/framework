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
    public static function noPolicy(string $subject): self
    {
        return new self(
            "No policy is registered for {$subject}. Every #[Authorize] needs one; "
            . 'register it, or declare #[NoAuthorization(reason: ...)] on the entry point.'
        );
    }

    public static function noAbility(string $policy, string $ability): self
    {
        return new self("{$policy} has no '{$ability}' method, and no before() hook answered it.");
    }

    public static function badSubject(string $ability): self
    {
        return new self(
            "#[Authorize('{$ability}')] has no subject to check. Name the model-typed input it covers, "
            . 'or the class, as in Authorize(\'viewAny\', Rack::class).'
        );
    }
}
