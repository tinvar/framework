<?php

declare(strict_types=1);

namespace Tinvar\Query;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\Gate;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Orm\Orm;

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
    public function __construct(
        private readonly QueryRegistry $registry,
        private readonly Orm $orm,
        private readonly Gate $gate = new AllowAllGate(),
        private readonly Encoder $encoder = new Encoder(),
        private readonly bool $debug = false,
    ) {}

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

        foreach ($calls as $call) {
            $prepared = $this->prepare($call, $allowed);
            if (isset($prepared['error'])) {
                $errors[$call['id']] = $prepared['error'];
                continue;
            }

            // Dedupe on the *resolved* arguments: "Identical calls collapse into
            // one; results are memoized for the request, matching React cache()
            // semantics."
            $arguments = $prepared['args'];
            $key = $call['name'] . "\0" . self::canonical($arguments);
            $groups[$key] ??= ['name' => $call['name'], 'args' => $arguments, 'ids' => []];
            $groups[$key]['ids'][] = $call['id'];
            $groupOf[$call['id']] = $key;
        }

        $values = $groups === [] ? [] : $this->execute($groups);

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
     * Allowlist, then argument resolution, then #[Authorize] -- the spec's
     * order, with step 2 doing real work.
     *
     * @param array{id: int, name: string, args: array<string, mixed>} $call
     * @param list<string> $allowed
     * @return array{args: array<string, mixed>}|array{error: array{code: string, digest: string, message?: string}}
     */
    private function prepare(array $call, array $allowed): array
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

        // The gate sees exactly the map the body will receive, so a check named
        // for an argument cannot be satisfied by a differently-shaped call.
        foreach ($this->registry->checksFor($name) as $check) {
            if ($check->argument !== null && !\array_key_exists($check->argument, $arguments)) {
                return ['error' => QueryError::of(
                    QueryError::FORBIDDEN,
                    "'{$name}' declares #[Authorize] for '{$check->argument}', which this call does not supply",
                    $this->debug,
                )];
            }

            if (!$this->gate->allows($check, $arguments)) {
                return ['error' => QueryError::of(
                    QueryError::FORBIDDEN,
                    "'{$check->ability}' denied for '{$name}'",
                    $this->debug,
                )];
            }
        }

        return ['args' => $arguments];
    }

    /**
     * Step 2 of the order: resolve arguments against the query's declaration.
     *
     * Returns the normalized map, or a string describing why the call is
     * malformed. Integer keys are refused outright: spreading one would bind
     * positionally and could satisfy a call whose named argument a gate is
     * checking.
     *
     * @param array<string, mixed> $args
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

        $resolved = [];
        foreach ($declared as $parameter => $required) {
            if (\array_key_exists($parameter, $args)) {
                $resolved[$parameter] = $args[$parameter];
                continue;
            }
            if ($required) {
                return "'{$name}' requires an argument named '{$parameter}'";
            }
        }

        // A query with no declared parameters still gets its named arguments,
        // checked for key shape above.
        return $declared === [] ? $args : $resolved;
    }

    /**
     * @param array<string, array{name: string, args: array<string, mixed>, ids: list<int>}> $groups
     * @return array<string, array{value?: mixed, error?: array{code: string, digest: string, message?: string}}>
     */
    private function execute(array $groups): array
    {
        $scheduler = $this->orm->scheduler() ?? $this->orm->beginRender();

        /** @var array<string, list<string>> $byQuery batch-capable query name => group keys */
        $byQuery = [];
        /** @var array<string, \Closure(): mixed> $tasks */
        $tasks = [];

        foreach ($groups as $key => $group) {
            if ($this->registry->isBatch($group['name'])) {
                $byQuery[$group['name']][] = $key;
                continue;
            }

            $query = $this->registry->instanceFor($group['name']);
            $arguments = $group['args'];
            $tasks[$key] = static fn (): mixed => $query(...$arguments);
        }

        // One fiber per batch query, carrying every call made to it this tick.
        foreach ($byQuery as $name => $keys) {
            /** @var BatchQuery $query */
            $query = $this->registry->instanceFor($name);
            $argumentSets = array_map(static fn (string $k): array => $groups[$k]['args'], $keys);

            $tasks['batch:' . $name] = static fn (): array => $query->loadMany(array_values($argumentSets));
        }

        $raw = $scheduler->run($tasks);

        $outcomes = [];
        foreach ($groups as $key => $group) {
            if (!$this->registry->isBatch($group['name'])) {
                $outcomes[$key] = $this->settle($raw[$key] ?? null);
            }
        }

        foreach ($byQuery as $name => $keys) {
            $batch = $raw['batch:' . $name] ?? null;

            if ($batch instanceof \Throwable) {
                foreach ($keys as $key) {
                    $outcomes[$key] = ['error' => QueryError::of(QueryError::FAILED, $batch, $this->debug)];
                }
                continue;
            }

            if (!\is_array($batch) || \count($batch) !== \count($keys)) {
                $detail = sprintf(
                    '%s::loadMany() returned %s for %d call(s)',
                    $this->registry->classFor($name) ?? $name,
                    get_debug_type($batch),
                    \count($keys),
                );
                foreach ($keys as $key) {
                    $outcomes[$key] = ['error' => QueryError::of(QueryError::BAD_RESULT, $detail, $this->debug)];
                }
                continue;
            }

            foreach (array_values($keys) as $index => $key) {
                $outcomes[$key] = $this->settle(array_values($batch)[$index]);
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
    private function settle(mixed $value): array
    {
        if ($value instanceof \Throwable) {
            return ['error' => QueryError::of(QueryError::FAILED, $value, $this->debug)];
        }

        try {
            return ['value' => $this->encoder->encode($value)];
        } catch (DataBoundaryException $e) {
            return ['error' => QueryError::of(QueryError::BAD_RESULT, $e, $this->debug)];
        }
    }

    /** @param array<string, mixed> $args */
    private static function canonical(array $args): string
    {
        ksort($args);

        return json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
