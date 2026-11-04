<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Actions\ActionRunner;
use Tinvar\Http\ExceptionHandler;
use Tinvar\Http\HttpException;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRunner;
use Tinvar\Validation\ValidationException;

/**
 * Runs an action or a query for a caller that is not a browser, through the
 * same runner a browser's call goes through, and nothing else.
 *
 * An action runs through ActionRunner: its model ids resolved in the tenant
 * (a foreign or missing one is not found), every #[Authorize] decided
 * against the current user, every form request and parameter validated --
 * typed properties as the schema, rule attributes for the rest -- and only
 * then the body, whose result leaves through the Data encoder. A query runs
 * through QueryRunner, in a read-only transaction, its arguments held to
 * its declaration, its #[Authorize] decided before the cache, its value
 * encoded. What the caller sends never reaches a body as anything but what
 * the validator built from it.
 *
 * In front of both, the arguments are held to what the operation declares:
 * a JSON object of plain data, no deeper than MAX_DEPTH and no larger than
 * MAX_BYTES, naming only its inputs. A form post's extra fields are ignored;
 * a model's are refused, because a model that sends a field the operation
 * does not have has misread the schema, and the call is better retried than
 * run without what it meant.
 *
 * The context -- tenant, panel, user -- is the caller's to have entered and
 * checked: the tool calls' and the service calls' modules, each against its
 * own token. This runs in whatever is in force.
 */
final readonly class CallRunner
{
    /** The largest arguments, as JSON: the action endpoint's body limit is far above what a tool call needs. */
    public const int MAX_BYTES = 65_536;

    /** How deep arguments may nest. */
    public const int MAX_DEPTH = 8;

    /**
     * @param (\Closure(\Throwable): string)|null $report logs a failure and returns its digest;
     *        the digest alone, logging nothing, unless given
     */
    public function __construct(
        private ActionRunner $actions,
        private QueryRunner $queries,
        private ?\Closure $report = null,
    ) {}

    /** @param array<array-key, mixed> $arguments as the caller sent them */
    public function run(Operation $operation, array $arguments): CallResult
    {
        $refused = self::admit($operation, $arguments);
        if ($refused !== null) {
            return $refused;
        }
        /** @var array<string, mixed> $arguments */

        return $operation->kind === Operation::ACTION
            ? $this->action($operation, $arguments)
            : $this->query($operation, $arguments);
    }

    /** @param array<string, mixed> $arguments */
    private function action(Operation $operation, array $arguments): CallResult
    {
        try {
            $result = $this->actions->run($operation->name, $arguments);
        } catch (ValidationException $e) {
            return CallResult::invalid($e->errors);
        } catch (HttpException $e) {
            return match ($e->status) {
                401, 403 => CallResult::forbidden(),
                404 => CallResult::notFound(),
                // A refusal the caller can act on -- a plan, a state, a limit --
                // by its status and code; never its message. A 5xx is the
                // application's own trouble, and a failure with a digest.
                default => $e->status >= 400 && $e->status < 500
                    ? CallResult::rejected($e->status, $e->code_)
                    : CallResult::failed($this->report($e)),
            };
        } catch (\Throwable $e) {
            return CallResult::failed($this->report($e));
        }

        // A redirect has no value: what the action did is done, and there
        // is no browser to send anywhere.
        return CallResult::ok($result->isRedirect() ? null : $result->value, $result->flash);
    }

    /** @param array<string, mixed> $arguments */
    private function query(Operation $operation, array $arguments): CallResult
    {
        try {
            // Its own name is its allowlist: whoever let the call this far
            // named the query, and nothing else is reachable through it.
            $result = $this->queries->run([['id' => 0, 'name' => $operation->name, 'args' => $arguments]], [$operation->name])[0];
        } catch (\Throwable $e) {
            return CallResult::failed($this->report($e));
        }

        if (!isset($result['error'])) {
            return CallResult::ok($result['value'] ?? null);
        }

        return match ($result['error']['code']) {
            QueryError::FORBIDDEN => CallResult::forbidden(),
            QueryError::NOT_FOUND => CallResult::notFound(),
            // The runner's reason names the argument, and is for the log;
            // the caller is told which call was malformed, not how.
            QueryError::BAD_ARGUMENTS => CallResult::invalid(['arguments' => ['The arguments do not match what this takes.']]),
            default => CallResult::failed($result['error']['digest']),
        };
    }

    /**
     * Refuses arguments that are not a JSON object of plain data naming the
     * operation's inputs, before anything is resolved or validated. run()
     * asks it first; a caller that spends something on a call -- a
     * single-use service context token -- asks it before spending, so a call
     * refused for its shape, which nothing ran for, spends nothing.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function admit(Operation $operation, array $arguments): ?CallResult
    {
        if ($arguments !== [] && array_is_list($arguments)) {
            return CallResult::invalid(['arguments' => ['The arguments are an object of named values.']]);
        }
        if (!self::plain($arguments, 1)) {
            return CallResult::invalid(['arguments' => ['The arguments are plain data, at most ' . self::MAX_DEPTH . ' deep.']]);
        }
        try {
            $json = json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return CallResult::invalid(['arguments' => ['The arguments have no JSON form.']]);
        }
        if (\strlen($json) > self::MAX_BYTES) {
            return CallResult::invalid(['arguments' => ['The arguments are larger than ' . self::MAX_BYTES . ' bytes.']]);
        }

        $errors = [];
        foreach (array_keys($arguments) as $name) {
            if (!\is_string($name) || !isset($operation->inputs[$name])) {
                // The name is the caller's own text, so it is not repeated.
                $errors['arguments'] = ['A field was sent that this does not take: send only the fields its schema lists.'];
            }
        }

        return $errors === [] ? null : CallResult::invalid($errors);
    }

    private static function plain(mixed $value, int $depth): bool
    {
        if (\is_array($value)) {
            if ($depth > self::MAX_DEPTH) {
                return false;
            }
            foreach ($value as $item) {
                if (!self::plain($item, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || \is_scalar($value);
    }

    private function report(\Throwable $e): string
    {
        return $this->report === null ? ExceptionHandler::digest($e) : ($this->report)($e);
    }
}
