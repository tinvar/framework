<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

use Tinvar\Actions\TagInvalidation;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PanelContext;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Http\HttpException;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\RawBody;
use Tinvar\Http\EntryGates;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Trashed;
use Tinvar\Routing\Binder;
use Tinvar\Support\Reflected;
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
 *   4. run the body -- read-only for a GET, which carries no CSRF token --
 *      and, for a method that may write, invalidate the tags it declares
 *   5. answer: its Response, its Data through the encoder as JSON, or 204
 *
 * Steps 1 and 2 come before 3 for the reason they do for an action:
 * validation can query through Exists and Unique, and nothing should query
 * for a caller who is not allowed to be here. A Precognition call
 * (precognition()) runs 1 to 3 and stops, read-only.
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
        // What the tags an endpoint declares do once a call that may write
        // has run (ADR 0075); none, and they do nothing.
        private readonly ?TagInvalidation $invalidation = null,
        // Who the call is, and the panel it is in: put back around a
        // streamed body, which is produced after both have been reset.
        private readonly ?AuthContext $auth = null,
        private readonly ?PanelContext $panels = null,
    ) {}

    /**
     * @param array<string, string> $path the path's parameters, decoded
     * @param array<string, mixed>  $input the query string for a GET, the body otherwise
     */
    public function run(CompiledEndpoint $endpoint, array $path, array $input, Request $request): Response
    {
        // 1. Resolve, and 2. authorize.
        [$models, $subject, $setAside] = $this->resolveAndAuthorize($endpoint, $path, $input);

        // A check set aside for a model field left out: "is required", and
        // nothing else -- no rule has been cleared to answer this caller.
        if ($setAside !== []) {
            throw new ValidationException($this->requiredOnly($endpoint, $input, $setAside, $models, $subject));
        }

        // Every check passed: then its gates (#[RequiresPlan]), which a
        // caller the checks refuse never reaches (ADR 0150).
        EntryGates::enforce($request);

        // 3. Validate, every form request before anything is reported.
        $requests = $this->validate($endpoint, $input, $models, $subject);

        // 4. The body. A GET's runs read-only, as a loader's does: it
        //    carries no CSRF token, so a page on another site can have a
        //    visitor's browser send one, and nothing it does may write.
        $arguments = $this->bind($endpoint, $path, $models, $requests, $request);
        $instance = ($this->resolver)($endpoint->class);
        $body = static fn (): mixed => $instance->{$endpoint->method}(...$arguments);
        $safe = CompiledEndpoint::isSafeMethod($request->method);
        $tags = [];
        if ($safe) {
            $result = $this->orm->readOnly($body);
        } else {
            // Its tags, each placeholder filled from the path -- a model by
            // its key, a scalar as bound -- before anything is written, so a
            // segment no tag may carry is refused with nothing changed
            // (ADR 0106).
            try {
                $tags = TagInvalidation::filled($endpoint->revalidates, [...$path, ...$models, ...array_intersect_key($arguments, $endpoint->scalars)]);
            } catch (\InvalidArgumentException) {
                throw new HttpException(400, 'A path parameter holds a character this endpoint cannot name.', 'bad_request');
            }

            // Noting every tenant it ran as, as an action's body is: a row
            // it wrote by actAs()ing a child is the child's, and so are the
            // pages to tell.
            [$result, $tenants] = $this->orm->tenantsEnteredBy($body);

            // What it said it invalidates, as an action's result does: the
            // cached queries and the once-shared holds at once and again
            // after any commit, the live clients and the static pages once
            // the writes are in (TagInvalidation). Once the body has
            // returned -- one that threw changed nothing to announce -- and
            // never for a GET, which wrote nothing.
            $this->invalidation?->invalidateIn($tags, $tenants);
        }

        // 5. Answer.
        $response = match ($endpoint->returns) {
            CompiledEndpoint::RETURNS_RESPONSE => $result instanceof Response
                ? $this->streamedInScope($safe ? $result : self::seeOther($request->method, $result), $safe)
                : throw new \LogicException("{$endpoint->where()} returned " . get_debug_type($result) . ', not a Response.'),
            CompiledEndpoint::RETURNS_DATA => $this->json($endpoint, $result),
            default => Response::make(204),
        };

        // "An action's response lists the server-side query tags it
        // invalidated (X-Invalidated-Tags), and the client flushes matching
        // prefetch entries" -- an endpoint's that wrote, too, so the caller's
        // own prefetched pages and fragments are not left showing what it
        // changed (ADR 0106). The tags as filled, never another's: they are
        // what this call declared, about this call's path.
        return $tags === [] ? $response : $response->withHeader('x-invalidated-tags', implode(',', $tags));
    }

    /**
     * "Redirects after PUT/PATCH/DELETE become 303" (tinvar-spec.md,
     * "Protocol", kept from Inertia). A client following a 301 or 302
     * changes the method to GET only after a POST: fetch() follows one
     * answering a DELETE with another DELETE, at the target. 303 says GET
     * whatever came before. 307 and 308 keep the method on purpose, and are
     * the endpoint's to send.
     */
    private static function seeOther(string $method, Response $response): Response
    {
        return \in_array($method, ['PUT', 'PATCH', 'DELETE'], true) && \in_array($response->status, [301, 302], true)
            ? $response->withStatus(303)
            : $response;
    }

    /**
     * Precognition (ADR 0074): the order up to validation, and no body --
     * the models resolved in the tenant, every check, then the form
     * requests, all of them or only the fields named. Read-only whatever
     * the method: nothing that answers "would this do?" may do anything.
     *
     * @param array<string, string> $path
     * @param array<string, mixed>  $input
     * @param list<string>|null     $only the fields to judge; null for every form request whole
     * @param Request|null          $request the call, whose gates run once the checks have passed
     * @return array<string, list<string>> each field's errors; none when the input would do
     * @throws UnknownField when $only names a field no form request declares
     */
    public function precognition(CompiledEndpoint $endpoint, array $path, array $input, ?array $only, ?Request $request = null): array
    {
        return $this->orm->readOnly(function () use ($endpoint, $path, $input, $only, $request): array {
            [$models, $subject, $setAside] = $this->resolveAndAuthorize($endpoint, $path, $input);
            // Its gates once the checks it can decide have passed: an answer
            // to "would this do?" is behind them, as the call is (ADR 0150).
            EntryGates::enforce($request);

            // As run() answers: the field left out is required, and every
            // other field waits for it, since none has been cleared to be
            // judged -- the database rules least of all.
            if ($setAside !== []) {
                $required = $this->requiredOnly($endpoint, $input, $setAside, $models, $subject);
                if ($only === null) {
                    return $required;
                }
                foreach ($only as $field) {
                    if (!$this->declares($endpoint, $field)) {
                        throw new UnknownField('A field no form request of this endpoint declares.');
                    }
                }

                return array_intersect_key($required, array_flip($only));
            }

            if ($only === null) {
                try {
                    $this->validate($endpoint, $input, $models, $subject);
                } catch (ValidationException $e) {
                    return $e->errors;
                }

                return [];
            }

            // Each field by every form request declaring it, as a submit
            // judges it by each; one no form request declares is refused
            // rather than read as fine.
            $unsupplied = array_diff_key($endpoint->allModels(), $models);
            $errors = [];
            foreach ($only as $field) {
                $declared = false;
                foreach ($endpoint->formRequests as $class) {
                    foreach (Reflected::constructorParameters($class) as $parameter) {
                        if ($parameter->getName() !== $field) {
                            continue;
                        }
                        $declared = true;
                        $found = $this->validator->errorsFor($class, $input, $field, $subject, $models, $models, $unsupplied);
                        if ($found !== []) {
                            $errors[$field] = array_values(array_unique([...$errors[$field] ?? [], ...$found]));
                        }
                    }
                }
                if (!$declared) {
                    throw new UnknownField('A field no form request of this endpoint declares.');
                }
            }

            return $errors;
        });
    }

    /**
     * Steps 1 and 2: the path's models and the form requests' model fields
     * resolved in the tenant -- a missing or foreign id a 404 -- and every
     * check asked of them.
     *
     * @param array<string, string> $path
     * @param array<string, mixed>  $input
     * @return array{array<string, Model>, Model|null, list<string>} the models, the subject a form
     *         request is judged against, and the fields whose checks were set aside because the call left them out
     */
    private function resolveAndAuthorize(CompiledEndpoint $endpoint, array $path, array $input): array
    {
        $models = [];
        foreach ($endpoint->models as $parameter => $class) {
            $id = $path[$parameter] ?? '';
            // Out of scope reads as absent, never as forbidden.
            $models[$parameter] = $this->orm->find($class, $id, isset($endpoint->trashedModels[$parameter]) ? Trashed::With : Trashed::Without)
                ?? throw NotFoundException::model($class, $id);
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

        $setAside = $this->authorize($endpoint, $models, $unsent);

        $subject = null;
        foreach ($endpoint->checks as $index => $check) {
            if ($index >= $endpoint->classLevelChecks && $check->argument !== null && isset($models[$check->argument])) {
                $subject = $models[$check->argument];
                break;
            }
        }

        return [$models, $subject, $setAside];
    }

    /**
     * What a call is told while a check on a model field it left out is set
     * aside, as an action's is (ActionRunner): each such field's "is
     * required", judged with the database rules standing down, and nothing
     * else. A field that drew no error is refused as undecided, rather than
     * let the call reach the body with a check unasked.
     *
     * @param array<string, mixed> $input
     * @param non-empty-list<string> $fields
     * @param array<string, Model> $models
     * @return non-empty-array<string, non-empty-list<string>>
     */
    private function requiredOnly(CompiledEndpoint $endpoint, array $input, array $fields, array $models, ?Model $subject): array
    {
        return $this->validator->requiredOnly($endpoint->formRequests, $input, $fields, $subject, $models, array_diff_key($endpoint->allModels(), $models), $endpoint->where());
    }

    /** Whether a form request of the endpoint declares a field of this name. */
    private function declares(CompiledEndpoint $endpoint, string $field): bool
    {
        foreach ($endpoint->formRequests as $class) {
            foreach (Reflected::constructorParameters($class) as $parameter) {
                if ($parameter->getName() === $field) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Step 3: every form request validated before anything is reported.
     *
     * @param array<string, mixed> $input
     * @param array<string, Model> $models
     * @return array<string, FormRequest>
     * @throws ValidationException with every form request's errors together
     */
    private function validate(CompiledEndpoint $endpoint, array $input, array $models, ?Model $subject): array
    {
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

        return $requests;
    }

    /**
     * A streamed Response, its producer run as the call it answers. It runs
     * after the body has returned, as the response is sent -- after the
     * tenant and the panel have been left and the Kernel has reset the
     * request's scope -- so what was in place here is put back around it:
     * the tenant, the caller, the panel and the membership it was granted.
     * And a GET's read-only too, since a producer that wrote would be a
     * write a page on another site could cause. The file withFile() named
     * and the cookies are kept.
     */
    private function streamedInScope(Response $response, bool $readOnly): Response
    {
        if (!$response->isStreaming()) {
            return $response;
        }

        $scope = EnteredScope::capture($this->orm, $this->auth, $this->panels, $readOnly);

        return $response->aroundBody(static function (\Closure $produce, \Closure $sink) use ($scope): void {
            $scope->run(static function () use ($produce, $sink): void {
                $produce($sink);
            });
        });
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
     * @return list<string> the fields whose checks were set aside, because the call left them out
     */
    private function authorize(CompiledEndpoint $endpoint, array $models, array $unsent): array
    {
        // Answered, for a check set aside, with that field's "is required"
        // alone (requiredOnly()); see Checks::authorize().
        return Checks::authorize($this->gate, $endpoint->checks, $endpoint->classLevelChecks, $models, array_keys($endpoint->optionalModels), $unsent, $endpoint->where());
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
        foreach (Reflected::methodParameters($endpoint->class, $endpoint->method) as $parameter) {
            $name = $parameter->getName();

            $arguments[$name] = match (true) {
                isset($endpoint->models[$name]) => $models[$name],
                isset($endpoint->scalars[$name]) => Binder::cast($path[$name] ?? '', $parameter->getType()),
                isset($requests[$name]) => $requests[$name],
                // Set by EndpointHandler before the endpoint's middleware
                // ran, which refused a request without one (ADR 0183).
                $name === $endpoint->rawBody => RawBody::of($request)
                    ?? throw new \LogicException("{$endpoint->where()} takes the RawBody, and its request carries none."),
                default => $request,
            };
        }

        return $arguments;
    }
}
