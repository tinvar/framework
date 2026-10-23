<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Authorize;

/**
 * Evaluates #[Authorize] against a policy.
 *
 * "Authorization is declarative and runs before anything else: before a cache
 * lookup, validation, batching or Precognition. Checks written inside a method
 * body are skipped whenever the body is, so the framework never relies on
 * them."
 *
 * Every path through this class either returns a policy's decision or throws.
 * There is no default-allow: a missing policy or a missing ability is a
 * declaration bug and fails the request rather than quietly permitting it.
 */
final class PolicyGate implements Gate
{
    public function __construct(
        private readonly PolicyRegistry $policies,
        private readonly AuthContext $auth,
    ) {}

    public function allows(Authorize $check, array $arguments): bool
    {
        $subject = $this->subjectFor($check, $arguments);
        $subjectClass = \is_object($subject) ? $subject::class : $subject;
        $policy = $this->policies->for($subjectClass);
        $user = $this->auth->user();

        // A before() hook answers for every ability on that policy: the usual
        // place for "an owner may do anything here". Returning null means it
        // has no opinion and the ability method decides.
        if (method_exists($policy, 'before')) {
            $verdict = $policy->before($user, $check->ability, $subject);
            if ($verdict !== null) {
                return (bool) $verdict;
            }
        }

        if (!method_exists($policy, $check->ability)) {
            throw AuthorizationException::noAbility($policy::class, $check->ability);
        }

        return (bool) $policy->{$check->ability}($user, $subject, $arguments);
    }

    /**
     * What this check is about, taken from the attribute.
     *
     * Deliberately not a 'subject' key in $arguments: the caller's map may be
     * shaped by the request, and a gate that reads a subject out of it could
     * be pointed at a different record from the one the body will use. The
     * attribute names the argument; the argument must already hold a resolved
     * model, or be a class name written in the source.
     *
     * @param array<string, mixed> $arguments
     */
    private function subjectFor(Authorize $check, array $arguments): object|string
    {
        $argument = $check->argument;
        if ($argument === null) {
            throw AuthorizationException::badSubject($check->ability);
        }

        // A class named in the attribute is source, not request data.
        if (class_exists($argument) || interface_exists($argument)) {
            return $argument;
        }

        $value = $arguments[$argument] ?? null;

        // A scalar here means resolution did not happen, which would leave the
        // policy deciding about a string. Fail closed rather than guess.
        if (!\is_object($value)) {
            throw AuthorizationException::unresolvedSubject(
                $check->ability,
                $argument,
                get_debug_type($value),
            );
        }

        return $value;
    }
}
