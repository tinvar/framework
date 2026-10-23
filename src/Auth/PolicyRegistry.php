<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * Maps a model to the policy that decides about it.
 *
 * Subclasses inherit a parent's policy, so a hierarchy does not need one entry
 * per leaf -- but an exact match always wins.
 */
final class PolicyRegistry
{
    /** @var array<class-string, class-string> */
    private array $policies = [];

    /** @var array<class-string, object> */
    private array $instances = [];

    /** @var array<class-string, class-string|''> subject => policy class, '' for none */
    private array $resolved = [];

    /** @param \Closure(class-string): object|null $resolver */
    public function __construct(private readonly ?\Closure $resolver = null) {}

    /**
     * @param class-string $subject
     * @param class-string $policy
     */
    public function register(string $subject, string $policy): self
    {
        $this->policies[$subject] = $policy;
        $this->resolved = [];

        return $this;
    }

    /** @param array<class-string, class-string> $map */
    public function registerAll(array $map): self
    {
        foreach ($map as $subject => $policy) {
            $this->register($subject, $policy);
        }

        return $this;
    }

    /** @param class-string $subject */
    public function hasFor(string $subject): bool
    {
        return $this->policyClassFor($subject) !== null;
    }

    /**
     * The policy object for a subject.
     *
     * Built with `new`, a policy has no dependencies, and one instance until
     * the next reset() is enough. Built by the application's resolver, it may
     * hold services -- or a user it copied out of AuthContext -- and how long
     * those live is the resolver's to decide. The registry lives as long as
     * whatever holds it, the worker's life in the Kernel, and kept such a
     * policy until something remembered to call reset(): its Request-lived
     * services and its user then answered for the next caller, and for any
     * actingAs() begun after it was built. So the resolver is asked every
     * time, as QueryRegistry::instanceFor() does: a container keeps what
     * should be kept.
     *
     * @param class-string $subject
     */
    public function for(string $subject): object
    {
        $class = $this->policyClassFor($subject) ?? throw AuthorizationException::noPolicy($subject);

        if ($this->resolver !== null) {
            return ($this->resolver)($class);
        }

        return $this->instances[$class] ??= new $class();
    }

    /**
     * Exact match first, then the MOST DERIVED registered ancestor.
     *
     * Iterating the registration map and taking the first ancestor made the
     * answer depend on the order registerAll() happened to receive: a
     * permissive parent policy would shadow a stricter child's, or not,
     * depending on insertion order. Two unrelated matches (two interfaces, say)
     * are ambiguous and refused rather than picked arbitrarily.
     *
     * @param class-string $subject
     * @return class-string|null
     */
    private function policyClassFor(string $subject): ?string
    {
        // '' is the memo for "none", so a repeated miss does not re-walk.
        $cached = $this->resolved[$subject] ??= $this->resolve($subject) ?? '';

        return $cached === '' ? null : $cached;
    }

    /**
     * @param class-string $subject
     * @return class-string|null
     */
    private function resolve(string $subject): ?string
    {
        if (isset($this->policies[$subject])) {
            return $this->policies[$subject];
        }

        /** @var list<class-string> $matches */
        $matches = [];
        foreach (array_keys($this->policies) as $registered) {
            if (is_a($subject, $registered, true)) {
                $matches[] = $registered;
            }
        }

        if ($matches === []) {
            return null;
        }

        // Keep only the most derived: drop any match that another match
        // extends or implements.
        $mostDerived = array_values(array_filter(
            $matches,
            static function (string $candidate) use ($matches): bool {
                foreach ($matches as $other) {
                    if ($other !== $candidate && is_a($other, $candidate, true)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        if (\count($mostDerived) > 1) {
            throw AuthorizationException::ambiguousPolicy($subject, $mostDerived);
        }

        return $this->policies[$mostDerived[0]];
    }

    /** Clears the instance cache. Called on the request and job teardown. */
    public function reset(): void
    {
        $this->instances = [];
    }

    /** @return array<class-string, class-string> */
    public function all(): array
    {
        return $this->policies;
    }
}
