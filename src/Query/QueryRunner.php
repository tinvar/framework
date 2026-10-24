<?php

declare(strict_types=1);

namespace Tinvar\Query;

use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Support\Uuid;

/**
 * Runs one tick's worth of query calls.
 *
 * Order, from tinvar-spec.md "Order for every query call":
 *   1. the query name must be on the route's allowlist
 *   2. resolve arguments
 *   3. #[Authorize] per call; denied calls fail individually, before the batch runs
 *   4. cache lookup (Phase 1)
 *   5. run the body
 *
 * Identical calls collapse into one. Calls to a BatchQuery are handed to it as a
 * group, so twenty of them become one statement. Everything runs inside the
 * fiber scheduler, so relation loads reached from different queries batch too.
 */
final class QueryRunner
{
    /**
     * @param (\Closure(\Throwable, ?string): string)|null $report logs what a
     *        query threw under the digest its error record carries -- the
     *        Kernel's report(). The record keeps the exception's text away
     *        from the renderer, which is right, and with nothing else it kept
     *        it from everyone: a fragment rescued because its query failed
     *        showed a reference no log line had.
     */
    public function __construct(
        private readonly QueryRegistry $registry,
        private readonly Orm $orm,
        private readonly Gate $gate,
        private readonly Encoder $encoder = new Encoder(),
        private readonly bool $debug = false,
        private readonly ?QueryCache $cache = null,
        private readonly ?\Closure $report = null,
    ) {}

    /**
     * Cache tags the queries in this render depend on.
     *
     * "Revalidation reaches mounted fragments. When an action revalidates a
     * tag, the client re-fetches any mounted fragment that read it, not the
     * whole page." A fragment can only declare what it read if something
     * records it, and the only place that knows is where the tags are
     * computed -- so they are collected as queries run, hit or miss.
     *
     * @var array<string, true>
     */
    private array $tagsRead = [];

    /**
     * @return list<string> the tags this runner has seen, tenant-namespaced
     *         exactly as QueryCache stores them
     */
    public function tagsRead(): array
    {
        return array_keys($this->tagsRead);
    }

    public function forgetTagsRead(): void
    {
        $this->tagsRead = [];
    }

    /**
     * The #[Middleware] a query declares, for an HTTP call to run around it.
     *
     * @return list<class-string<\Tinvar\Http\Middleware>>
     */
    public function middlewareFor(string $name): array
    {
        return $this->registry->middlewareFor($name);
    }

    /** @return class-string<\Tinvar\Tenancy\TenantSource>|null see QueryRegistry::tenantSourceFor() */
    public function tenantSourceFor(string $name): ?string
    {
        return $this->registry->tenantSourceFor($name);
    }

    /**
     * @param list<array{id: int, name: string, args: array<string, mixed>}> $calls
     * @param list<string> $allowed per-route query allowlist from the render frame
     * @return list<array{id: int, value?: mixed, error?: array{code: string, digest: string, message?: string}}>
     */
    public function run(array $calls, array $allowed): array
    {
        /** @var array<int, array{code: string, digest: string, message?: string}> $errors */
        $errors = [];
        /** @var array<string, array{name: string, args: array<string, mixed>, ids: list<int>}> $groups */
        $groups = [];
        /** @var array<int, string> $groupOf call id => group key */
        $groupOf = [];
        /** @var array<int, array{name: string, args: array<string, mixed>, key: string}> $accepted */
        $accepted = [];

        // Call ids come from the renderer, which the trust boundary calls
        // semi-trusted. Everything below is keyed by id, so a repeated one
        // would collapse two calls into one -- and the survivor's data would
        // be returned under an id whose own arguments were never authorized.
        $seen = [];
        foreach ($calls as $call) {
            if (isset($seen[$call['id']])) {
                throw ProtocolException::malformed(Frame::QUERY, 'two calls share id ' . $call['id']);
            }
            $seen[$call['id']] = true;
        }

        // Step 1: the allowlist and the shape of the arguments.
        foreach ($calls as $call) {
            $prepared = $this->admit($call, $allowed);
            if (isset($prepared['error'])) {
                $errors[$call['id']] = $prepared['error'];
                continue;
            }
            $accepted[$call['id']] = ['name' => $call['name'], 'args' => $prepared['args'], 'key' => $prepared['key']];
        }

        // Step 2: resolve model arguments, scoped to the tenant -- batched, so
        // 42 calls naming 42 units cost one statement, not 42.
        $models = $this->resolveModels($accepted);

        foreach ($accepted as $id => $call) {
            $resolved = $this->substitute($call, $models);
            if (isset($resolved['error'])) {
                $errors[$id] = $resolved['error'];
                continue;
            }

            // Step 3: authorize, against the resolved model.
            $denial = $this->authorize($call['name'], $resolved['args']);
            if ($denial !== null) {
                $errors[$id] = $denial;
                continue;
            }

            // Dedupe on the validated arguments, before substitution: models
            // are objects and would not key stably. Keyed in admit(), where an
            // argument with no key fails its own call.
            $key = $call['name'] . "\0" . $call['key'];
            $groups[$key] ??= ['name' => $call['name'], 'args' => $resolved['args'], 'ids' => []];
            $groups[$key]['ids'][] = $id;
            $groupOf[$id] = $key;
        }

        // One read-only transaction for the tick. Each body and each encode
        // is a read-only scope of its own, and a body that never suspends
        // finishes before the next starts, so without this every scope began
        // and ended a transaction of its own: on the 24-card catalog, the
        // batch and 24 encodes, fifty statements the page did not ask for.
        $values = $groups === [] ? [] : $this->orm->readOnly(fn (): array => $this->execute($groups));

        $results = [];
        foreach ($calls as $call) {
            $id = $call['id'];
            if (isset($errors[$id])) {
                $results[] = ['id' => $id, 'error' => $errors[$id]];
                continue;
            }

            $outcome = $values[$groupOf[$id]];
            $results[] = isset($outcome['error'])
                ? ['id' => $id, 'error' => $outcome['error']]
                : ['id' => $id, 'value' => $outcome['value']];
        }

        return $results;
    }

    /**
     * One whereIn per model class, for every call in the tick.
     *
     * @param array<int, array{name: string, args: array<string, mixed>}> $accepted
     * @return array<class-string<Model>, array<string, Model>>
     */
    private function resolveModels(array $accepted): array
    {
        /** @var array<class-string<Model>, list<string>> $wanted */
        $wanted = [];

        foreach ($accepted as $call) {
            foreach ($this->registry->modelArgumentsFor($call['name']) as $argument => $class) {
                $value = $call['args'][$argument] ?? null;

                // Orm::find()'s guard, on the batched path that bypasses it.
                // Every key is a UUID, so anything else names no row and is
                // left out: substitute() then answers that one call as not
                // found. Sent to the database, Postgres refused the malformed
                // value for a uuid column, which failed the whole tick and
                // quoted the caller's input in the error.
                if ((\is_string($value) || \is_int($value)) && Uuid::isValid((string) $value)) {
                    $wanted[$class][] = self::idOf($value);
                }
            }
        }

        $resolved = [];
        foreach ($wanted as $class => $ids) {
            $missing = [];

            // The identity map holds anything read earlier in this request,
            // keyed by tenant, so a hit is a scoped hit -- but only for a
            // model a row actually produced.
            //
            // A #[BelongsTo] registers a lazy ghost from a foreign key before
            // anything confirms the row exists. Left untouched it is an
            // uninitialized ghost; touched with no row behind it, the
            // initializer returns without hydrating and leaves an initialized
            // but empty one. isPersisted() is true only after markClean(),
            // which happens on hydrate from a real row and after an insert --
            // so it distinguishes both cases from a genuine read.
            //
            // The map is also bypassed while a read is unscoped, as it is
            // everywhere else, for the same reason.
            $trustMap = !$this->orm->isUnscoped();

            foreach (array_unique($ids) as $id) {
                $known = $trustMap ? $this->orm->identityMap()->get($class, $id) : null;

                // Orm::holdsRow checks laziness before persistence, so this
                // cannot force a ghost to initialize just by asking about it.
                if ($known instanceof Model && Orm::holdsRow($known)) {
                    $resolved[$class][$id] = $known;
                    continue;
                }

                $missing[] = $id;
            }

            if ($missing === []) {
                continue;
            }

            $meta = Metadata::for($class);
            foreach ($this->orm->whereIn($class, $meta->idColumn, $missing) as $model) {
                $resolved[$class][$model->primaryKey()] = $model;
            }
        }

        return $resolved;
    }

    /**
     * @param array{name: string, args: array<string, mixed>} $call
     * @param array<class-string, array<string, Model>> $models
     * @return array{args: array<string, mixed>}|array{error: array{code: string, digest: string, message?: string}}
     */
    private function substitute(array $call, array $models): array
    {
        $args = $call['args'];

        foreach ($this->registry->modelArgumentsFor($call['name']) as $argument => $class) {
            // Null only ever reaches here for an optional model the call
            // left out (resolveArguments() refuses it for a required one),
            // and the body is handed it as the absence it is.
            if (!\array_key_exists($argument, $args) || $args[$argument] === null) {
                continue;
            }

            $id = self::idOf($args[$argument]);
            $model = $models[$class][$id] ?? null;

            // Out of scope reads as absent, never as forbidden, so the
            // response cannot be used to probe another tenant.
            if ($model === null) {
                return ['error' => QueryError::of(
                    QueryError::NOT_FOUND,
                    "no {$class} with the given id is in scope for '{$call['name']}'",
                    $this->debug,
                )];
            }

            $args[$argument] = $model;
        }

        return ['args' => $args];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{code: string, digest: string, message?: string}|null
     */
    private function authorize(string $name, array $arguments): ?array
    {
        // Declared-optional model arguments. A required one that is missing
        // never reaches here -- admit() refuses the call first -- so this can
        // only skip a check whose subject the query itself says may be absent.
        $optional = [];
        $declared = $this->registry->parametersFor($name);
        foreach ($this->registry->modelArgumentsFor($name) as $argument => $class) {
            if (($declared[$argument] ?? true) === false) {
                $optional[] = $argument;
            }
        }

        $checks = $this->registry->checksFor($name);

        // Every check named an optional input and the call left them all
        // out: nothing would be asked, and the body would still run. The
        // class's checks and the entry method's are judged apart, so one
        // place's unconditional check cannot stand in for the other's.
        if (Checks::undecided($checks, $this->registry->classLevelChecksFor($name), $arguments, $optional)) {
            return QueryError::of(
                QueryError::FORBIDDEN,
                "no check declared on '{$name}' applies to this call",
                $this->debug,
            );
        }

        foreach ($checks as $check) {
            if (!Checks::applies($check, $arguments, $optional)) {
                continue;
            }

            try {
                $allowed = $this->gate->allows($check, $arguments);
            } catch (\Throwable $e) {
                // A declaration bug -- a missing policy, an ability that is
                // not public, a check naming an unresolved argument -- must
                // fail its own call. Letting it out of run() loses every other
                // result in the tick, and the message would bypass the code
                // and digest that keep exception text away from the renderer.
                return $this->failed(QueryError::MISCONFIGURED, $e);
            }

            if (!$allowed) {
                return QueryError::of(
                    QueryError::FORBIDDEN,
                    "'{$check->ability}' denied for '{$name}'",
                    $this->debug,
                );
            }
        }

        return null;
    }

    /**
     * Step 1 of the order: the route's allowlist, and the shape of the
     * arguments. Resolution and authorization follow, in run().
     *
     * @param array{id: int, name: string, args: array<string, mixed>} $call
     * @param list<string> $allowed
     * @return array{args: array<string, mixed>, key: string}|array{error: array{code: string, digest: string, message?: string}}
     */
    private function admit(array $call, array $allowed): array
    {
        $name = $call['name'];

        // "PHP rejects any other name on that route's socket, so a compromised
        // dependency can't call queries the page never uses."
        if (!\in_array($name, $allowed, true)) {
            return ['error' => QueryError::of(
                QueryError::NOT_ALLOWED,
                "'{$name}' is not on this route's allowlist",
                $this->debug,
            )];
        }

        if (!$this->registry->has($name)) {
            return ['error' => QueryError::of(
                QueryError::UNKNOWN,
                "no query is registered as '{$name}'",
                $this->debug,
            )];
        }

        $arguments = $this->resolveArguments($name, $call['args']);
        if (\is_string($arguments)) {
            return ['error' => QueryError::of(QueryError::BAD_ARGUMENTS, $arguments, $this->debug)];
        }

        // The dedupe key, made here so an argument that has none fails its
        // own call. NaN, infinity and bytes that are not UTF-8 all cross the
        // socket and have no JSON form; thrown from run(), one of them lost
        // every other result in the tick.
        try {
            $key = self::canonical($arguments);
        } catch (\JsonException $e) {
            return ['error' => QueryError::of(
                QueryError::BAD_ARGUMENTS,
                "'{$name}' was called with an argument that has no JSON form: {$e->getMessage()}",
                $this->debug,
            )];
        }

        return ['args' => $arguments, 'key' => $key];
    }

    /**
     * Step 2 of the order: resolve arguments against the query's declaration.
     *
     * Returns the normalized map, or a string describing why the call is
     * malformed. Integer keys are refused outright: spreading one would bind
     * positionally and could satisfy a call whose named argument a gate is
     * checking.
     *
     * @param array<array-key, mixed> $args as the renderer sent them; keys unchecked
     * @return array<string, mixed>|string
     */
    private function resolveArguments(string $name, array $args): array|string
    {
        $declared = $this->registry->parametersFor($name);

        foreach (array_keys($args) as $key) {
            if (!\is_string($key)) {
                return "'{$name}' was called with a positional argument; only named arguments cross the socket";
            }
            if ($declared !== [] && !\array_key_exists($key, $declared)) {
                return "'{$name}' does not declare an argument named '{$key}'";
            }
        }

        // A model argument is an identifier. Casting an array to string later
        // emits a warning on remote-controlled input before failing closed.
        //
        // Null for an OPTIONAL model is the absence of one -- a component's
        // `undefined` encodes as nil -- and is treated exactly as leaving
        // the key out, so it meets the same checks: Checks::applies() skips
        // only a check whose subject the query declares optional, and
        // Checks::undecided() still refuses a call that sidesteps them all.
        // For a required model it is still not an identifier.
        foreach ($this->registry->modelArgumentsFor($name) as $argument => $_) {
            if (!\array_key_exists($argument, $args)) {
                continue;
            }
            if ($args[$argument] === null && ($declared[$argument] ?? true) === false) {
                unset($args[$argument]);
                continue;
            }
            if (!\is_string($args[$argument]) && !\is_int($args[$argument])) {
                return "'{$name}' needs an identifier for '{$argument}'";
            }
        }

        // Optional because it accepts null, not because it has a default:
        // PHP has no value of its own to bind, so the absent argument is
        // given null rather than left out of a call it would fail.
        $nullWhenAbsent = $this->registry->nullWhenAbsentFor($name);

        $resolved = [];
        foreach ($declared as $parameter => $required) {
            if (\array_key_exists($parameter, $args)) {
                $resolved[$parameter] = $args[$parameter];
                continue;
            }
            if ($required) {
                return "'{$name}' requires an argument named '{$parameter}'";
            }
            if (\in_array($parameter, $nullWhenAbsent, true)) {
                $resolved[$parameter] = null;
            }
        }

        // A query with no declared parameters still gets its named arguments,
        // checked for key shape above.
        return $declared === [] ? $args : $resolved;
    }

    /**
     * @param array<string, array{name: string, args: array<string, mixed>, ids: list<int>}> $groups
     * @return array<string, array{error: array{code: string, digest: string, message?: string}}|array{value: mixed}>
     */
    private function execute(array $groups): array
    {
        $scheduler = $this->orm->scheduler() ?? $this->orm->beginRender();

        /** @var array<string, list<string>> $byQuery batch-capable query name => group keys */
        $byQuery = [];
        /** @var array<string, \Closure(): mixed> $tasks */
        $tasks = [];
        /** @var array<string, array{error: array{code: string, digest: string, message?: string}}|array{value: mixed}> $cached */
        $cached = [];

        foreach ($groups as $key => $group) {
            // The lookup is here, after the gate: "Declarative #[Authorize]
            // runs before the lookup, so a cache hit never skips it."
            $hit = $this->cachedValue($group['name'], $group['args']);
            if ($hit !== null) {
                $cached[$key] = $hit;
                continue;
            }

            if ($this->registry->isBatch($group['name'])) {
                $byQuery[$group['name']][] = $key;
                continue;
            }

            $query = $this->registry->instanceFor($group['name']);
            if (!\is_callable($query)) {
                throw new \LogicException("Query '{$group['name']}' is not invocable: it needs __invoke() or to be a BatchQuery.");
            }
            $arguments = $group['args'];
            // "Loaders and queries run in a read-only transaction."
            $orm = $this->orm;
            $tasks[$key] = static fn (): mixed => $orm->readOnly(
                static fn (): mixed => $query(...$arguments),
            );
        }

        // One fiber per batch query, carrying every call made to it this tick.
        foreach ($byQuery as $name => $keys) {
            $query = $this->registry->instanceFor($name);
            if (!$query instanceof BatchQuery) {
                throw new \LogicException("Query '{$name}' is registered as a batch but its instance is not a BatchQuery.");
            }
            $argumentSets = array_map(static fn (string $k): array => $groups[$k]['args'], $keys);

            $orm = $this->orm;
            $tasks['batch:' . $name] = static fn (): array => $orm->readOnly(
                static fn (): array => $query->loadMany($argumentSets),
            );
        }

        $raw = $tasks === [] ? [] : $scheduler->run($tasks);

        $outcomes = $cached;
        foreach ($groups as $key => $group) {
            if (isset($outcomes[$key]) || $this->registry->isBatch($group['name'])) {
                continue;
            }

            $outcomes[$key] = $this->settle($raw[$key] ?? null);
            $this->remember($group['name'], $group['args'], $outcomes[$key]);
        }

        foreach ($byQuery as $name => $keys) {
            $batch = $raw['batch:' . $name] ?? null;

            if ($batch instanceof \Throwable) {
                // One failure, reported once, however many calls it fails.
                $error = $this->failed(QueryError::FAILED, $batch);
                foreach ($keys as $key) {
                    $outcomes[$key] = ['error' => $error];
                }
                continue;
            }

            // A list, exactly one per call, or nothing is answered. An array
            // keyed by call index but filled in the order rows came back has
            // the right count; realigned by position, call A got call B's
            // data. Which result was meant for which call cannot be
            // recovered from any other shape, so none of them is guessed at.
            if (!\is_array($batch) || !array_is_list($batch) || \count($batch) !== \count($keys)) {
                $detail = sprintf(
                    '%s::loadMany() returned %s for %d call(s); it must return a list, one per call, in call order',
                    $this->registry->classFor($name) ?? $name,
                    \is_array($batch) && !array_is_list($batch) ? 'an array that is not a list' : get_debug_type($batch),
                    \count($keys),
                );
                foreach ($keys as $key) {
                    $outcomes[$key] = ['error' => QueryError::of(QueryError::BAD_RESULT, $detail, $this->debug)];
                }
                continue;
            }

            foreach ($keys as $index => $key) {
                $outcomes[$key] = $this->settle($batch[$index]);
                $this->remember($name, $groups[$key]['args'], $outcomes[$key]);
            }
        }

        return $outcomes;
    }

    /**
     * Encodes one result, which is where the data boundary is enforced for
     * queries: a model returned by a query body never reaches the renderer.
     *
     * @return array{value?: mixed, error?: array{code: string, digest: string, message?: string}}
     */
    /**
     * A cached value for this call, or null for a miss.
     *
     * @param array<string, mixed> $arguments
     * @return array{value: mixed}|null
     */
    private function cachedValue(string $name, array $arguments): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        $declaration = $this->registry->declarationFor($name);

        // Recorded on the way IN, so a hit declares its tags too -- a
        // fragment served from cache still depends on them, and a client
        // that only learned about tags on a miss would stop re-fetching
        // exactly the boundaries the cache is keeping warm.
        $this->recordTags($declaration, $arguments);

        $hit = $this->cache->get($name, $declaration, $arguments, $this->orm->tenant());

        return $hit['hit'] ? ['value' => $hit['value']] : null;
    }

    /**
     * Stores a successful result. A failure is never cached: an error is about
     * this attempt, not about the data.
     *
     * @param array<string, mixed> $arguments
     * @param array{error: array{code: string, digest: string, message?: string}}|array{value: mixed} $outcome
     */
    private function remember(string $name, array $arguments, array $outcome): void
    {
        if ($this->cache === null || isset($outcome['error'])) {
            return;
        }

        $this->cache->put(
            $name,
            $this->registry->declarationFor($name),
            $arguments,
            $this->orm->tenant(),
            $outcome['value'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function recordTags(QueryAttribute $declaration, array $arguments): void
    {
        if ($this->cache === null || $declaration->tags === null) {
            return;
        }

        try {
            foreach ($this->cache->tagsFor($declaration, $arguments, $this->orm->tenant()) as $tag) {
                $this->tagsRead[$tag] = true;
            }
        } catch (\Throwable) {
            // A mistyped tag closure is already reported by QueryCache, which
            // treats it as "do not cache this one". Failing the render over
            // it here would turn a caching bug into an outage.
        }
    }

    /** @return array{error: array{code: string, digest: string, message?: string}}|array{value: mixed} */
    private function settle(mixed $value): array
    {
        if ($value instanceof \Throwable) {
            return ['error' => $this->failed(QueryError::FAILED, $value)];
        }

        // Read-only, as the body was: encoding resolves the result's Lazy
        // fields, which is the body's work deferred, not new work allowed to
        // write. A Lazy made in the body carries the scope already; this
        // covers one the body returned without making.
        try {
            return ['value' => $this->orm->readOnly(fn (): mixed => $this->encoder->encode($value))];
        } catch (DataBoundaryException $e) {
            return ['error' => $this->failed(QueryError::BAD_RESULT, $e)];
        } catch (\Throwable $e) {
            // A Lazy field failing -- a refused write among them -- is this
            // call failing, as a throw from the body would be. Escaping, it
            // failed every other call in the tick with it.
            return ['error' => $this->failed(QueryError::FAILED, $e)];
        }
    }

    /**
     * The record for a call that failed because something threw, with the
     * throwable reported under the record's own digest -- the one the
     * renderer, and a rescued boundary after it, will show.
     *
     * @return array{code: string, digest: string, message?: string}
     */
    private function failed(string $code, \Throwable $e): array
    {
        $error = QueryError::of($code, $e, $this->debug);
        if ($this->report !== null) {
            ($this->report)($e, $error['digest']);
        }

        return $error;
    }

    /**
     * A model argument as the key its row is filed under.
     *
     * Lower-cased: every key is a UUID, which names the same row in either
     * case, and the database and the identity map hand rows back under the
     * lower-case primaryKey(). Used verbatim, an upper-case id was looked up
     * under a key nothing was filed under, and a row that exists answered
     * NOT_FOUND.
     */
    private static function idOf(mixed $value): string
    {
        return strtolower(\is_string($value) || \is_int($value) ? (string) $value : '');
    }

    /** @param array<string, mixed> $args */
    private static function canonical(array $args): string
    {
        ksort($args);

        return json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
