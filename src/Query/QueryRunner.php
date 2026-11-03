<?php

declare(strict_types=1);

namespace Tinvar\Query;

use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Data\SharedReads;
use Tinvar\Features\FlagReads;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Support\Uuid;
use Tinvar\Http\EntryGates;
use Tinvar\Http\Request;

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
     * What a shared read's task and outcome are filed under in a tick.
     * `$` cannot begin a query's group key, whose name is a dotted
     * identifier, nor a batch's, which begins `batch:`.
     */
    private const string SHARED_TASK = '$shared:';

    /**
     * @param (\Closure(\Throwable, ?string): string)|null $report logs what a
     *        query threw under the digest its error record carries -- the
     *        Kernel's report(). The record keeps the exception's text away
     *        from the renderer, which is right, and with nothing else it kept
     *        it from everyone: a fragment rescued because its query failed
     *        showed a reference no log line had.
     * @param (\Closure(string): ?string)|null $panels why a call to a query may not run
     *        in the request's panel as it stands, or null when it may -- the
     *        Kernel's queryPanelRefusal(). Without it, a query that declares a
     *        #[Panel] is refused on every call: a render runs queries behind
     *        its page's panel, not theirs, and nothing here could tell
     *        whether the page's covers the query's.
     * @param (\Closure(string, array<string, mixed>, mixed): void)|null $returned told the
     *        name, arguments and value of each call whose body returned, before the
     *        encoder sees the value -- for the testing layer, which hands a test the
     *        Data object a query returned rather than its encoding. Never told of a
     *        cache hit, which has no value but its encoding.
     * @param (\Closure(string): ?\Closure)|null $standIn what answers in place of a
     *        query's body, by its name, or null for the body -- a test's fake
     *        (StandIns::query()); none in production. Asked only for a call that
     *        passed every step before the body, and what it returns is encoded
     *        as the body's value would be, so a fake returning a model fails as
     *        production would.
     */
    public function __construct(
        private readonly QueryRegistry $registry,
        private readonly Orm $orm,
        private readonly Gate $gate,
        private readonly Encoder $encoder = new Encoder(),
        private readonly bool $debug = false,
        private readonly ?QueryCache $cache = null,
        private readonly ?\Closure $report = null,
        private readonly ?\Closure $panels = null,
        private readonly ?\Closure $returned = null,
        private readonly ?\Closure $standIn = null,
    ) {
        $this->validator = new Validator();
    }

    /** What a call's scalar arguments are coerced by, as an action's are. */
    private readonly Validator $validator;

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
     * @return list<string>
     */
    public function middlewareFor(string $name): array
    {
        return $this->registry->middlewareFor($name);
    }

    /** @return class-string|null see QueryRegistry::tenantSourceFor() */
    public function tenantSourceFor(string $name): ?string
    {
        return $this->registry->tenantSourceFor($name);
    }

    /** See QueryRegistry::hostFor(). */
    public function hostFor(string $name): ?string
    {
        return $this->registry->hostFor($name);
    }

    /**
     * A query's #[Panel] declarations, with its class -- whose namespace
     * places it when it declares none; see QueryRegistry::panelsFor().
     *
     * @return array{list<\Tinvar\Attributes\Panel>, class-string}|null null for a name not registered
     */
    public function panelsFor(string $name): ?array
    {
        $class = $this->registry->classFor($name);

        return $class === null ? null : [$this->registry->panelsFor($name), $class];
    }

    /**
     * @param list<array{id: int, name: string, args: array<string, mixed>}> $calls
     * @param list<string> $allowed per-route query allowlist from the render frame
     * @param SharedReads|null $shared the render's shared data, read by calls named
     *        `$shared.key` beside the queries of the tick; none -- an HTTP call, a
     *        render handed none -- and every such call is refused (ADR 0060)
     * @param Request|null $request the HTTP call to /_query it answers, whose
     *        gates (#[RequiresPlan]) run for a call once its checks have
     *        passed (ADR 0150); null for a render's, which run behind its page's
     * @return list<array{id: int, value?: mixed, error?: array{code: string, digest: string, message?: string}}>
     */
    public function run(array $calls, array $allowed, ?SharedReads $shared = null, ?Request $request = null): array
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

        // Shared reads first, apart: a key is not a query, and is on no
        // allowlist; what admits one is the render's own SharedReads.
        /** @var array<string, list<int>> $sharedIds key => the calls reading it */
        $sharedIds = [];

        // Step 1: the allowlist and the shape of the arguments -- and the
        // query's panel, which is the request context it needs before any
        // of its arguments is resolved.
        foreach ($calls as $call) {
            $key = SharedReads::keyOf($call['name']);
            if ($key !== null) {
                $refused = $this->admitShared($key, $call['args'], $shared);
                if ($refused !== null) {
                    $errors[$call['id']] = $refused;
                    continue;
                }
                $sharedIds[$key][] = $call['id'];
                $groupOf[$call['id']] = self::SHARED_TASK . $key;
                continue;
            }

            $prepared = $this->admit($call, $allowed);
            if (isset($prepared['error'])) {
                $errors[$call['id']] = $prepared['error'];
                continue;
            }
            $refused = $this->panelRefusal($call['name']);
            if ($refused !== null) {
                $errors[$call['id']] = $refused;
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
            // Then its gates, which a caller the checks refuse never reaches.
            EntryGates::enforce($request);

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
        $values = $groups === [] && $sharedIds === []
            ? []
            : $this->orm->readOnly(fn (): array => $this->execute($groups, array_keys($sharedIds), $shared));

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
     * A call refused for its query's panel, or null.
     *
     * @return array{code: string, digest: string, message?: string}|null
     */
    private function panelRefusal(string $name): ?array
    {
        if ($this->panels === null) {
            return $this->registry->panelsFor($name) === []
                ? null
                : QueryError::of(QueryError::MISCONFIGURED, "Query {$name} declares a #[Panel], and this runner has no way to hold a call to it.", $this->debug);
        }

        $why = ($this->panels)($name);

        return $why === null ? null : QueryError::of(QueryError::FORBIDDEN, $why, $this->debug);
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
            // The map is also bypassed while a read is unscoped or across a
            // subtree, as it is everywhere else, for the same reason.
            $trustMap = !$this->orm->isWidened();

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
            // A query that declares none takes none: handed through, an
            // unknown named argument failed inside the call, an internal
            // error for the caller's mistake.
            if (!\array_key_exists($key, $declared)) {
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

        // A scalar, an array or a backed enum, coerced to its declared type as an
        // action's parameter is: a case arrives as its backing value and an
        // int as digits from a form or a URL, and spread as they came, each
        // was a TypeError inside the call -- a failure for the caller's
        // mistake. A value the type has no place for is bad arguments.
        $class = $this->registry->classFor($name) ?? $name;
        foreach ($this->registry->scalarParametersFor($name) as $argument => $parameter) {
            if (!\array_key_exists($argument, $args) || ($args[$argument] === null && $parameter->allowsNull())) {
                continue;
            }
            try {
                $args[$argument] = $this->validator->coerceParameter($parameter, $class, $args[$argument]);
            } catch (ValidationException $e) {
                return "'{$name}' was called with an '{$argument}' its type has no place for: "
                    . implode(' ', $e->errors[$argument] ?? []);
            }
        }
        // A batch's, typed by #[Query(args:)] rather than a signature, held
        // to their types the same way rather than handed to loadMany() as sent.
        foreach ($this->registry->scalarPlansFor($name) as $argument => $plan) {
            if (!\array_key_exists($argument, $args)) {
                continue;
            }
            try {
                $args[$argument] = $this->validator->coerceField($plan, $args[$argument]);
            } catch (ValidationException $e) {
                return "'{$name}' was called with an '{$argument}' its type has no place for: "
                    . implode(' ', $e->errors[$argument] ?? []);
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

        return $resolved;
    }

    /**
     * @param array<string, array{name: string, args: array<string, mixed>, ids: list<int>}> $groups
     * @param list<string> $sharedKeys keys read this tick, each admitted by $shared
     * @return array<string, array{error: array{code: string, digest: string, message?: string}}|array{value: mixed}>
     */
    private function execute(array $groups, array $sharedKeys = [], ?SharedReads $shared = null): array
    {
        $scheduler = $this->orm->scheduler() ?? $this->orm->beginRender();

        /** @var array<string, list<string>> $byQuery batch-capable query name => group keys */
        $byQuery = [];
        /** @var array<string, \Closure(): mixed> $tasks */
        $tasks = [];
        /** @var array<string, array{error: array{code: string, digest: string, message?: string}}|array{value: mixed}> $cached */
        $cached = [];
        /** @var array<string, true> $faked group keys a stand-in answers */
        $faked = [];
        /**
         * What each task read of the feature flags, by task key: what its
         * answer is cached under, or why it is not cached (ADR 0094). Every
         * task has one, cached or not, so a flag one body reads is never
         * taken for its neighbour's.
         *
         * @var array<string, FlagReads> $reads
         */
        $reads = [];

        foreach ($groups as $key => $group) {
            // A test's fake answers in place of the body, and only the body:
            // every step before it has run. Its call is never looked up in the
            // cache, where a real answer would hide it, nor stored there, where
            // it would be served as one; and never batched, since it answers
            // each call by itself. What it read is still recorded, as any
            // call's is.
            $standIn = $this->standIn === null ? null : ($this->standIn)($group['name']);
            if ($standIn !== null) {
                $this->recordTags($this->registry->declarationFor($group['name']), $group['args']);
                $arguments = $group['args'];
                $orm = $this->orm;
                $recorder = $reads[$key] = new FlagReads();
                $tasks[$key] = static fn (): mixed => $recorder->task(static fn (): mixed => $orm->readOnly(
                    static fn (): mixed => $standIn(...$arguments),
                ));
                $faked[$key] = true;
                continue;
            }

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
            $recorder = $reads[$key] = new FlagReads();
            $tasks[$key] = static fn (): mixed => $recorder->task(static fn (): mixed => $orm->readOnly(
                static fn (): mixed => $query(...$arguments),
            ));
        }

        // One fiber per batch query, carrying every call made to it this tick.
        foreach ($byQuery as $name => $keys) {
            $query = $this->registry->instanceFor($name);
            if (!$query instanceof BatchQuery) {
                throw new \LogicException("Query '{$name}' is registered as a batch but its instance is not a BatchQuery.");
            }
            $argumentSets = array_map(static fn (string $k): array => $groups[$k]['args'], $keys);

            // One body answers every call, so each call's answer is held to
            // every flag the batch read.
            $orm = $this->orm;
            $recorder = $reads['batch:' . $name] = new FlagReads();
            $tasks['batch:' . $name] = static fn (): array => $recorder->task(static fn (): array => $orm->readOnly(
                static fn (): array => $query->loadMany($argumentSets),
            ));
        }

        // A shared key read for the first time this render is a task beside
        // the queries': one fiber each, in the same run, so a relation it
        // reaches is loaded in the same batch as theirs. One read before is
        // answered from what it produced, never run again. None goes near
        // the query cache, in or out: a key is the visitor's own. A once
        // key the browser holds is answered as one read before, never run:
        // its outcome is the held marker (ADR 0065).
        $sharedOutcomes = [];
        $sharedRun = [];
        if ($shared !== null) {
            foreach ($sharedKeys as $key) {
                $known = $shared->outcome($key);
                if ($known !== null) {
                    $sharedOutcomes[self::SHARED_TASK . $key] = $known;
                    continue;
                }
                $orm = $this->orm;
                $recorder = $reads[self::SHARED_TASK . $key] = new FlagReads();
                $tasks[self::SHARED_TASK . $key] = static fn (): mixed => $recorder->task(static fn (): mixed => $orm->readOnly(
                    static fn (): mixed => $shared->produce($key),
                ));
                $sharedRun[] = $key;
            }
        }

        $raw = $tasks === [] ? [] : $scheduler->run($tasks);

        if ($shared !== null) {
            foreach ($sharedRun as $key) {
                // As it is kept: a once key's value as its once field -- held
                // by the browser only where it read no feature flag, since a
                // hold is believed until it expires whatever a flag does.
                $recorder = $reads[self::SHARED_TASK . $key];
                $settled = $recorder->task(fn (): array => $this->settleShared($key, $raw[self::SHARED_TASK . $key] ?? null, $shared));
                $sharedOutcomes[self::SHARED_TASK . $key] = $shared->remember($key, $settled, keepable: $recorder->clean());
            }
        }

        $outcomes = [...$cached, ...$sharedOutcomes];
        foreach ($groups as $key => $group) {
            if (isset($outcomes[$key]) || (!isset($faked[$key]) && $this->registry->isBatch($group['name']))) {
                continue;
            }

            $this->tell($group['name'], $group['args'], $raw[$key] ?? null);
            // Encoded under the task's recorder: a Lazy field resolved now is
            // the body's work deferred, and a flag it reads keys the answer
            // as one the body read would (ADR 0094).
            $outcomes[$key] = $reads[$key]->task(fn (): array => $this->settle($raw[$key] ?? null));
            if (!isset($faked[$key])) {
                $this->remember($group['name'], $group['args'], $outcomes[$key], $reads[$key]);
            }
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
                $this->tell($name, $groups[$key]['args'], $batch[$index]);
                $outcomes[$key] = $reads['batch:' . $name]->task(fn (): array => $this->settle($batch[$index]));
                $this->remember($name, $groups[$key]['args'], $outcomes[$key], $reads['batch:' . $name]);
            }
        }

        return $outcomes;
    }

    /**
     * A cached value for this call, or null for a miss.
     *
     * @param array<string, mixed> $arguments
     * @return array{value: mixed}|null
     */
    private function cachedValue(string $name, array $arguments): ?array
    {
        $declaration = $this->registry->declarationFor($name);

        // Recorded on the way IN, so a hit declares its tags too -- a
        // fragment served from cache still depends on them, and a client
        // that only learned about tags on a miss would stop re-fetching
        // exactly the boundaries the cache is keeping warm. And with no
        // cache at all: what a query read is what a live subscription is
        // scoped to, cached or not.
        $this->recordTags($declaration, $arguments);

        if ($this->cache === null) {
            return null;
        }

        // Keyed apart across the subtree as the Orm says, the one source the
        // reads and the tags above are scoped by.
        $hit = $this->cache->get($name, $declaration, $arguments, $this->orm->tenant(), $this->orm->isSubtreeGranted());

        return $hit['hit'] ? ['value' => $hit['value']] : null;
    }

    /**
     * Stores a successful result. A failure is never cached: an error is about
     * this attempt, not about the data.
     *
     * @param array<string, mixed> $arguments
     * @param array{error: array{code: string, digest: string, message?: string}}|array{value: mixed} $outcome
     */
    private function remember(string $name, array $arguments, array $outcome, FlagReads $reads): void
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
            $reads,
            $this->orm->isSubtreeGranted(),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function recordTags(QueryAttribute $declaration, array $arguments): void
    {
        if ($declaration->tags === null) {
            return;
        }

        // Under the subtree's namespace where a membership granted the call
        // its tenant's subtree: it may have read the rows of any tenant
        // below, and its page is told when one of them writes.
        try {
            foreach (QueryCache::tagsOf($declaration, $arguments, $this->orm->tenant(), $this->orm->isSubtreeGranted()) as $tag) {
                $this->tagsRead[$tag] = true;
            }
        } catch (\Throwable) {
            // A mistyped tag closure is already reported by QueryCache, which
            // treats it as "do not cache this one". Failing the render over
            // it here would turn a caching bug into an outage.
        }
    }

    /**
     * Hands a body's value to whoever asked to see it (see the constructor);
     * nothing for a body that threw.
     *
     * @param array<string, mixed> $arguments
     */
    private function tell(string $name, array $arguments, mixed $value): void
    {
        if ($this->returned !== null && !$value instanceof \Throwable) {
            ($this->returned)($name, $arguments, $value);
        }
    }

    /**
     * Why a read of a shared key is refused, or null when it may run.
     *
     * A key this render may not read -- none registered, or another
     * panel's -- is answered as unknown either way, so a page cannot learn
     * what another panel shares by asking.
     *
     * @param array<string, mixed> $args
     * @return array{code: string, digest: string, message?: string}|null
     */
    private function admitShared(string $key, array $args, ?SharedReads $shared): ?array
    {
        if ($shared === null) {
            return QueryError::of(QueryError::NOT_ALLOWED, "shared data is read only in a page or fragment render, and '{$key}' was asked for outside one", $this->debug);
        }
        if (!$shared->has($key)) {
            return QueryError::of(QueryError::UNKNOWN, "no shared value named '{$key}' reaches this page", $this->debug);
        }
        if ($args !== []) {
            return QueryError::of(QueryError::BAD_ARGUMENTS, "the shared value '{$key}' takes no arguments", $this->debug);
        }

        $why = $shared->scopeRefusal();

        return $why === null ? null : QueryError::of(QueryError::FORBIDDEN, $why, $this->debug);
    }

    /**
     * A shared key's outcome: its value encoded -- a model in it refused,
     * a ProvidesData in it told the key it is met under -- or its error.
     *
     * @return array{error: array{code: string, digest: string, message?: string}}|array{value: mixed}
     */
    private function settleShared(string $key, mixed $value, SharedReads $shared): array
    {
        if (!$value instanceof \Throwable && $value !== null && !$value instanceof Data) {
            return ['error' => $this->failed(QueryError::BAD_RESULT, new \UnexpectedValueException(
                "The shared value '{$key}' was " . get_debug_type($value) . '; a shared value is a Data object or null.',
            ))];
        }

        return $this->settle($value, $shared->contextFor(...), $key);
    }

    /**
     * Encodes one result, which is where the data boundary is enforced for
     * queries: a model returned by a query body never reaches the renderer.
     *
     * @param (\Closure(string): \Tinvar\Data\DataContext)|null $provide what a ProvidesData
     *        in the value is told; none for a query's result, which is placed on no page
     * @param string $segment what the value's dot paths are rooted at
     * @return array{error: array{code: string, digest: string, message?: string}}|array{value: mixed}
     */
    private function settle(mixed $value, ?\Closure $provide = null, string $segment = '$'): array
    {
        if ($value instanceof \Throwable) {
            return ['error' => $this->failed(QueryError::FAILED, $value)];
        }

        // Read-only, as the body was: encoding resolves the result's Lazy
        // fields, which is the body's work deferred, not new work allowed to
        // write. A Lazy made in the body carries the scope already; this
        // covers one the body returned without making.
        try {
            return ['value' => $this->orm->readOnly(fn (): mixed => $this->encoder->encode($value, null, $segment, $provide))];
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
