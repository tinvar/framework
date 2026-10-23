<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Orm\Orm;

/**
 * Everything that must be cleared between requests on a long-lived worker.
 *
 * tinvar-spec.md, "Tenancy": "Tenant, auth, panel, identity map and #[Scoped]
 * services reset at the end of each request and job, under a tested reset
 * contract."
 *
 * The contract was declared and never invoked. AuthContext::reset() said
 * "called at the end of every request and job" and had no caller anywhere,
 * not even a test; PolicyRegistry::reset() likewise. On the worker this
 * framework is built around, that means the previous request's user persisted
 * into the next one -- an authentication carry-over that was unreachable only
 * because nothing composed the request path yet.
 *
 * This is the owner. It is a list rather than an interface so that anything
 * request-scoped can join it, including things the framework does not define,
 * and so the test can assert the list covers what exists.
 */
final class RequestScope
{
    /** @var list<array{name: string, reset: \Closure(): void}> */
    private array $resets = [];

    /** The resettables the framework itself owns. */
    public static function standard(Orm $orm, AuthContext $auth, PolicyRegistry $policies): self
    {
        $scope = new self();

        // The identity map and the relation-row caches: one request, one map.
        $scope->add('orm', $orm->flush(...));

        // The current user. Carrying this over is the worst of the three.
        $scope->add('auth', $auth->reset(...));

        // Policy instances may memoise per-request answers.
        $scope->add('policies', $policies->reset(...));

        return $scope;
    }

    /** @param \Closure(): void $reset */
    public function add(string $name, \Closure $reset): self
    {
        $this->resets[] = ['name' => $name, 'reset' => $reset];

        return $this;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->resets, 'name');
    }

    /**
     * Runs every reset, even if one throws.
     *
     * A teardown that gives up halfway is worse than no teardown: it leaves
     * exactly the state it was supposed to clear, and the next request on this
     * worker inherits it. The first failure is rethrown once the rest have run.
     */
    public function reset(): void
    {
        $failure = null;

        foreach ($this->resets as $entry) {
            try {
                ($entry['reset'])();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
