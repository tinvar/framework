<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Routing\Binder;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

/**
 * Runs one endpoint, in the order the security model sets out for an
 * action -- its middleware having run upstream, in EndpointHandler:
 *
 *   1. resolve the path's models, and model-typed fields of its form
 *      requests, scoped to the tenant; a missing or foreign id is a 404
 *   2. run #[Authorize] for every model-typed input; denied is a 403
 *   3. validate the form requests; a failure is a 422 with its errors
 *   4. run the body -- read-only for a GET, which carries no CSRF token
 *   5. answer: its Response, its Data through the encoder as JSON, or 204
 *
 * Steps 1 and 2 come before 3 for the reason they do for an action:
 * validation can query through Exists and Unique, and nothing should query
 * for a caller who is not allowed to be here.
 */
final class EndpointRunner
{
    /**
     * @param \Closure(class-string): object $resolver builds the endpoint's class,
     *        as the Kernel builds pages and actions: afresh for every call, so
     *        nothing one instance memoised reaches the next user
     */
    public function __construct(
        private readonly \Closure $resolver,
        private readonly Orm $orm,
        private readonly Gate $gate,
        private readonly Validator $validator,
        private readonly Encoder $encoder = new Encoder(),
    ) {}

    /**
     * @param array<string, string> $path the path's parameters, decoded
     * @param array<string, mixed>  $input the query string for a GET, the body otherwise
     */
    public function run(CompiledEndpoint $endpoint, array $path, array $input, Request $request): Response
    {
        // 1. Resolve.
        $models = [];
        foreach ($endpoint->models as $parameter => $class) {
            $id = $path[$parameter] ?? '';
            // Out of scope reads as absent, never as forbidden.
            $models[$parameter] = $this->orm->find($class, $id) ?? throw NotFoundException::model($class, $id);
        }

        $unsent = [];
        foreach ($endpoint->nestedModels as $field => $class) {
            $id = $input[$field] ?? null;
            if ($id === null || $id === '') {
                if (!isset($endpoint->optionalModels[$field])) {
                    // The form request says "is required", in step 3; there
                    // is no id to call missing or foreign.
                    $unsent[] = $field;
                }
                continue;
            }
            if (!\is_string($id) && !\is_int($id)) {
                throw new NotFoundException("Endpoint '{$endpoint->name}' needs an identifier for '{$field}'.");
            }
            $models[$field] = $this->orm->find($class, (string) $id) ?? throw NotFoundException::model($class, (string) $id);
        }

        // 2. Authorize.
        $this->authorize($endpoint, $models, $unsent);

        // 3. Validate, every form request before anything is reported.
        $subject = null;
        foreach ($endpoint->checks as $index => $check) {
            if ($index >= $endpoint->classLevelChecks && $check->argument !== null && isset($models[$check->argument])) {
                $subject = $models[$check->argument];
                break;
            }
        }
        $unsupplied = array_diff_key($endpoint->allModels(), $models);
        $requests = [];
        $errors = [];
        foreach ($endpoint->formRequests as $parameter => $class) {
            try {
                $requests[$parameter] = $this->validator->validate($class, $input, $subject, $models, $models, $unsupplied);
            } catch (ValidationException $e) {
                foreach ($e->errors as $field => $messages) {
                    $errors[$field] = array_values(array_unique([...$errors[$field] ?? [], ...$messages]));
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // 4. The body. A GET's runs read-only, as a loader's does: it
        //    carries no CSRF token, so a page on another site can have a
        //    visitor's browser send one, and nothing it does may write.
        $arguments = $this->bind($endpoint, $path, $models, $requests, $request);
        $instance = ($this->resolver)($endpoint->class);
        $body = static fn (): mixed => $instance->{$endpoint->method}(...$arguments);
        $safe = CompiledEndpoint::isSafeMethod($request->method);
        $result = $safe ? $this->orm->readOnly($body) : $body();

        // 5. Answer.
        return match ($endpoint->returns) {
            CompiledEndpoint::RETURNS_RESPONSE => $result instanceof Response
                ? ($safe ? $this->streamedReadOnly($result) : $result)
                : throw new \LogicException("{$endpoint->where()} returned " . get_debug_type($result) . ', not a Response.'),
            CompiledEndpoint::RETURNS_DATA => $this->json($endpoint, $result),
            default => Response::make(204),
        };
    }

    /**
     * A GET's streamed Response, its producer run read-only too: it runs
     * after the body has returned, as the response is sent, and a producer
     * that wrote would be a write a page on another site could cause.
     */
    private function streamedReadOnly(Response $response): Response
    {
        if (!$response->isStreaming()) {
            return $response;
        }

        $orm = $this->orm;

        return Response::stream(
            static function (\Closure $sink) use ($orm, $response): void {
                $orm->readOnly(static function () use ($response, $sink): void {
                    $response->sendBody($sink);
                });
            },
            $response->status,
            $response->headers(),
        )->withCookiesOf($response);
    }

    /**
     * A Data object as JSON, through the encoder: only Data objects,
     * scalars, lists and maps leave, in every environment -- a model, or
     * any other object inside it, is refused.
     */
    private function json(CompiledEndpoint $endpoint, mixed $result): Response
    {
        if (!$result instanceof Data) {
            throw new \LogicException("{$endpoint->where()} returned " . get_debug_type($result) . ', not a Data object.');
        }

        $encoded = $this->orm->readOnly(fn (): mixed => $this->encoder->encode($result));

        return Response::make(
            200,
            json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['content-type' => 'application/json'],
        );
    }

    /**
     * As an action's: every check that applies is asked, and a call that
     * sidesteps every check the method declares -- each naming an optional
     * input it left out -- is undecided, and refused.
     *
     * @param array<string, Model> $models
     * @param list<string> $unsent required form-request models the call left out
     */
    private function authorize(CompiledEndpoint $endpoint, array $models, array $unsent): void
    {
        $checks = [];
        $classLevel = $endpoint->classLevelChecks;
        foreach ($endpoint->checks as $index => $check) {
            // A check on a required form field the caller left out has no
            // subject, and is set aside: the form request refuses the call
            // with "is required" before the body could run.
            if ($check->argument !== null && \in_array($check->argument, $unsent, true)) {
                if ($index < $endpoint->classLevelChecks) {
                    $classLevel--;
                }
                continue;
            }
            $checks[] = $check;
        }

        $optional = array_keys($endpoint->optionalModels);
        if (Checks::undecided($checks, $classLevel, $models, $optional)) {
            throw ForbiddenException::undecided($endpoint->where());
        }

        foreach ($checks as $check) {
            if (!Checks::applies($check, $models, $optional)) {
                continue;
            }
            if (!$this->gate->allows($check, $models)) {
                throw ForbiddenException::check($check->ability, $endpoint->where());
            }
        }
    }

    /**
     * @param array<string, string>      $path
     * @param array<string, Model>       $models
     * @param array<string, FormRequest> $requests
     * @return array<string, mixed>
     */
    private function bind(CompiledEndpoint $endpoint, array $path, array $models, array $requests, Request $request): array
    {
        $arguments = [];
        foreach ((new \ReflectionMethod($endpoint->class, $endpoint->method))->getParameters() as $parameter) {
            $name = $parameter->getName();

            $arguments[$name] = match (true) {
                isset($endpoint->models[$name]) => $models[$name],
                isset($endpoint->scalars[$name]) => Binder::cast($path[$name] ?? '', $parameter->getType()),
                isset($requests[$name]) => $requests[$name],
                default => $request,
            };
        }

        return $arguments;
    }
}
