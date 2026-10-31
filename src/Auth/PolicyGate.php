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
        if (method_exists($policy, 'before') && self::callable($policy, 'before')) {
            $verdict = $policy->before($user, $check->ability, $subject);
            if ($verdict !== null) {
                return self::decision($policy, 'before', $verdict);
            }
        }

        if (!self::callable($policy, $check->ability)) {
            throw AuthorizationException::noAbility($policy::class, $check->ability);
        }

        return self::decision(
            $policy,
            $check->ability,
            $policy->{$check->ability}($user, $subject, $arguments),
        );
    }

    /**
     * method_exists() is true for a private or protected method, which this
     * class cannot call: the attempt raises a raw Error that is neither a
     * decision nor an AuthorizationException.
     */
    private static function callable(object $policy, string $method): bool
    {
        // Once a worker for each policy and method: a class does not change
        // while it runs, and every check reflected the policy again. Only
        // what the class declares is kept, never a decision.
        $key = $policy::class . '::' . strtolower($method);
        $visibility = self::$visibility[$key] ??= (static function () use ($policy, $method): string {
            $reflection = new \ReflectionClass($policy);
            if (!$reflection->hasMethod($method)) {
                return 'none';
            }

            return $reflection->getMethod($method)->isPublic() ? 'public' : 'hidden';
        })();

        if ($visibility === 'hidden') {
            throw AuthorizationException::notPublic($policy::class, $method);
        }

        return $visibility === 'public';
    }

    /** @var array<string, 'none'|'public'|'hidden'> what callable() found, by policy class and method */
    private static array $visibility = [];

    /**
     * A policy answers with a bool or it has not answered.
     *
     * Casting with (bool) turns a reason string, an error array or a role name
     * into a permit. The class promises that every path returns a policy's
     * decision or throws; a truthy non-bool is not a decision.
     */
    private static function decision(object $policy, string $method, mixed $verdict): bool
    {
        if (!\is_bool($verdict)) {
            throw AuthorizationException::notBoolean($policy::class, $method, get_debug_type($verdict));
        }

        return $verdict;
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
     * @return object|class-string
     */
    private function subjectFor(Authorize $check, array $arguments): object|string
    {
        $argument = $check->argument;
        if ($argument === null) {
            throw AuthorizationException::badSubject($check->ability);
        }

        // The argument by that name first, as the build rules and the loader
        // runner read it. class_exists() is case-insensitive and sees every
        // declared class -- a built-in such as \Directory, any class_alias
        // -- so asking it first handed the policy a class-name string
        // whenever a model input happened to share a name with one, and a
        // policy that answers class-level abilities for a string then
        // decided about the class instead of the row.
        if (!\array_key_exists($argument, $arguments)
            && (class_exists($argument) || interface_exists($argument))) {
            // A class named in the attribute is source, not request data.
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
