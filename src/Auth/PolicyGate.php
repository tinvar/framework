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
        $subject = $arguments['subject'] ?? null;
        if ($subject === null) {
            throw AuthorizationException::badSubject($check->ability);
        }

        // A subject is either the resolved model or, for a class-level check
        // such as Authorize('viewAny', Rack::class), the class itself.
        $subjectClass = \is_object($subject) ? $subject::class : (string) $subject;
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
}
