<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\Encoder;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Validation\ValidationException;
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
        private readonly Encoder $encoder = new Encoder(),
        private readonly ?QueryCache $cache = null,
    ) {}

    /**
     * The #[Middleware] an action declares, on its class and its method.
     * None for a name that is not registered: that is answered with a 404
     * by run(), and there is no middleware to have declared.
     *
     * @return list<class-string>
     */
    public function middlewareFor(string $name): array
    {
        return $this->registry->has($name) ? $this->registry->get($name)->middleware : [];
    }

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

        $subject = $this->subjectOf($action, $models);

        // 4. Validate. A Precognition call stops here, and validates the form
        //    requests directly: binding the whole call first would 404 on a
        //    missing scalar, and a half-filled form is exactly the case
        //    Precognition exists for.
        if ($precognitive) {
            foreach ($action->formRequests as $class) {
                $this->validator->validate($class, $input, $subject, $models, $models);
            }

            return ActionResult::ok();
        }

        $arguments = $this->bind($action, $input, $models, $subject, $request);

        // 5. Run the body.
        $instance = $this->registry->instanceFor($action);
        $result = $instance->{$action->method}(...$arguments);

        if (!$result instanceof ActionResult) {
            throw ActionException::badReturn($action->where(), get_debug_type($result));
        }

        // 6. Invalidate the cached queries the action said it invalidated.
        //    revalidateTags() only ever reached the client, as an
        //    x-invalidated-tags header for its prefetch cache; the server-side
        //    cache was never touched, so a #[Query(cache: '5m')] kept serving
        //    the pre-write answer for the rest of its TTL.
        if ($this->cache !== null && $result->revalidatedTags !== []) {
            $this->cache->forgetTags($result->revalidatedTags, $this->orm->tenant());
        }

        // 7. The data boundary. Only Data objects, scalars, lists and maps
        //    leave, in every environment -- an action's value and flash were
        //    reaching the browser unchecked.
        return $result->encoded($this->encoder);
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

        $subject = $this->subjectOf($action, $models);

        // Every form request, not just the first: an action may take more
        // than one, and answering about a field of the second must not read
        // as "this field does not exist".
        foreach ($action->formRequests as $class) {
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $field) {
                    return $this->validator->errorsFor($class, $input, $field, $subject, $models, $models);
                }
            }
        }

        // A typo would otherwise return [] and read as "this field is fine",
        // which is the hazard Validator::errorsFor refuses one layer down.
        throw new \LogicException("Action '{$name}' has no form-request field named '{$field}'.");
    }

    /** @param array<string, Model> $models */
    private function subjectOf(CompiledAction $action, array $models): ?Model
    {
        return $action->subjectArgument === null ? null : ($models[$action->subjectArgument] ?? null);
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

            if ($id === null || $id === '') {
                // Optional means optional, as it already does in the
                // validator. A present but unresolvable id is still a 404.
                if (isset($action->optionalModels[$parameter])) {
                    continue;
                }

                throw new NotFoundException("Action '{$action->name}' needs an identifier for '{$parameter}'.");
            }

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
        $optional = array_keys($action->optionalModels);

        // Every check named an optional input and the call left them all
        // out: nothing would be asked, and the body would still run.
        if (Checks::noneApply($action->checks, $models, $optional)) {
            throw ForbiddenException::undecided($action->where());
        }

        foreach ($action->checks as $check) {
            // A check naming a declared-optional model input the caller did
            // not supply has no subject to decide about; the body gets null.
            if (!Checks::applies($check, $models, $optional)) {
                continue;
            }

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
                    $models,
                );
                continue;
            }

            if ($type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[$name] = $request ?? throw new \LogicException(
                    "{$action->where()} asks for the Request, but none was supplied.",
                );
                continue;
            }

            if (isset($action->models[$name]) || isset($action->nestedModels[$name])) {
                // Declared as a model but absent, and optional.
                $arguments[$name] = $parameter->isDefaultValueAvailable()
                    ? $parameter->getDefaultValue()
                    : null;
                continue;
            }

            if (\array_key_exists($name, $input)) {
                // Coerced, not passed through: a client sending name[]=x would
                // otherwise turn a 422 into a TypeError from inside the call.
                $arguments[$name] = $this->validator->coerceParameter($parameter, $action->where(), $input[$name]);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
                continue;
            }

            throw new ValidationException([$name => ["{$name} is required."]]);
        }

        return $arguments;
    }
}
