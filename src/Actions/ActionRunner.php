<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Validation\Validator;

/**
 * Runs one action, in the order the security model sets out.
 *
 * tinvar-spec.md, "Order for every action call":
 *   1. middleware (the router's job, upstream of this)
 *   2. resolve model arguments, and model-typed fields inside Data and form
 *      requests, scoped to the tenant; a missing or foreign id is a 404
 *   3. run #[Authorize] for every model-typed input; denied is a 403
 *   4. validate the form request; a Precognition call stops here
 *   5. run the method body
 *   6. re-render affected routes (the caller's job, from the ActionResult)
 *
 * Steps 2 and 3 come before 4 on purpose: validation can touch the database
 * through Exists and Unique, and nothing should query on behalf of a caller
 * who is not allowed to be here.
 */
final class ActionRunner
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly Orm $orm,
        private readonly Gate $gate,
        private readonly Validator $validator,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @param bool $precognitive validate-only: authorization and the form
     *        request run, the body does not
     */
    public function run(string $name, array $input, bool $precognitive = false, ?Request $request = null): ActionResult
    {
        $action = $this->registry->get($name);

        // 2. Resolve model arguments, scoped to the tenant.
        $models = $this->resolveModels($action, $input);

        // 3. Authorize, against the resolved models.
        $this->authorize($action, $models);

        // 4. Validate. The subject a Unique(ignoreSubject:) reads comes from
        //    here -- a model the request named but the server resolved -- not
        //    from the payload.
        $subject = $models === [] ? null : reset($models);
        $arguments = $this->bind($action, $input, $models, $subject, $request);

        if ($precognitive) {
            return ActionResult::ok();
        }

        // 5. Run the body.
        $instance = $this->registry->instanceFor($action);
        $result = $instance->{$action->method}(...$arguments);

        if (!$result instanceof ActionResult) {
            throw ActionException::badReturn($action->where(), get_debug_type($result));
        }

        return $result;
    }

    /**
     * Validate without running anything, for Precognition's
     * `validate('email')`.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function validateField(string $name, array $input, string $field): array
    {
        $action = $this->registry->get($name);
        $models = $this->resolveModels($action, $input);
        $this->authorize($action, $models);

        $subject = $models === [] ? null : reset($models);

        foreach ($action->formRequests as $class) {
            return $this->validator->errorsFor($class, $input, $field, $subject);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, Model>
     */
    private function resolveModels(CompiledAction $action, array $input): array
    {
        $models = [];

        // Both the action's own parameters and model-typed fields inside its
        // form requests: "resolve model arguments, and model-typed fields
        // inside Data and form requests, scoped to the tenant".
        foreach ($action->allModels() as $parameter => $class) {
            $id = $input[$parameter] ?? null;
            if (!\is_string($id) && !\is_int($id)) {
                throw new NotFoundException("Action '{$action->name}' needs an identifier for '{$parameter}'.");
            }

            // Out of scope reads as absent, never as forbidden.
            $models[$parameter] = $this->orm->find($class, (string) $id)
                ?? throw NotFoundException::model($class, (string) $id);
        }

        return $models;
    }

    /** @param array<string, Model> $models */
    private function authorize(CompiledAction $action, array $models): void
    {
        foreach ($action->checks as $check) {
            if (!$this->gate->allows($check, $models)) {
                throw ForbiddenException::check($check->ability, $action->where());
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, Model> $models
     * @return array<string, mixed>
     */
    private function bind(
        CompiledAction $action,
        array $input,
        array $models,
        ?Model $subject,
        ?Request $request,
    ): array {
        $arguments = [];

        foreach ((new \ReflectionMethod($action->class, $action->method))->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (isset($models[$name])) {
                $arguments[$name] = $models[$name];
                continue;
            }

            if (isset($action->formRequests[$name])) {
                // The already-resolved models are handed in, so validation
                // cannot resolve a different row from the one authorized.
                $arguments[$name] = $this->validator->validate(
                    $action->formRequests[$name],
                    $input,
                    $subject,
                    $models,
                );
                continue;
            }

            if ($type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[$name] = $request ?? throw new \LogicException(
                    "{$action->where()} asks for the Request, but none was supplied."
                );
                continue;
            }

            if (\array_key_exists($name, $input)) {
                $arguments[$name] = $input[$name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
                continue;
            }

            throw new NotFoundException("Action '{$action->name}' is missing '{$name}'.");
        }

        return $arguments;
    }
}
