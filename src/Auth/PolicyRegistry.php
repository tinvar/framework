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

    /** @param \Closure(class-string): object|null $resolver */
    public function __construct(private readonly ?\Closure $resolver = null) {}

    /**
     * @param class-string $subject
     * @param class-string $policy
     */
    public function register(string $subject, string $policy): self
    {
        $this->policies[$subject] = $policy;

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

    /** @param class-string $subject */
    public function for(string $subject): object
    {
        $class = $this->policyClassFor($subject) ?? throw AuthorizationException::noPolicy($subject);

        return $this->instances[$class] ??= $this->resolver !== null
            ? ($this->resolver)($class)
            : new $class();
    }

    /**
     * @param class-string $subject
     * @return class-string|null
     */
    private function policyClassFor(string $subject): ?string
    {
        if (isset($this->policies[$subject])) {
            return $this->policies[$subject];
        }

        foreach ($this->policies as $registered => $policy) {
            if (is_subclass_of($subject, $registered)) {
                return $policy;
            }
        }

        return null;
    }

    /** @return array<class-string, class-string> */
    public function all(): array
    {
        return $this->policies;
    }
}
