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
use Tinvar\Validation\FormRequest;
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
     * Where an action's tenant comes from, by its #[TenantFrom]; null for
     * none, or for a name that is not registered.
     *
     * @return class-string<\Tinvar\Tenancy\TenantSource>|null
     */
    public function tenantSourceFor(string $name): ?string
    {
        return $this->registry->has($name) ? $this->registry->get($name)->tenantFrom : null;
    }

    /**
     * An action's #[Panel] declarations, with its holder class -- whose
     * namespace places it when it declares none.
     *
     * @return array{list<\Tinvar\Attributes\Panel>, class-string}|null null for a name not registered
     */
    public function panelsFor(string $name): ?array
    {
        if (!$this->registry->has($name)) {
            return null;
        }
        $action = $this->registry->get($name);

        /** @var class-string $class */
        $class = $action->class;

        return [$action->panels, $class];
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
        [$models, $unsent] = $this->resolveModels($action, $input);

        // 3. Authorize, against the resolved models.
        $this->authorize($action, $models, $unsent);

        $subject = $this->subjectOf($action, $models);

        // 4. Validate, every form request and scalar parameter before
        //    anything is reported. A Precognition call stops here.
        [$requests, $scalars] = $this->validateInputs($action, $input, $subject, $models);

        if ($precognitive) {
            return ActionResult::ok();
        }

        $arguments = $this->bind($action, $models, $requests, $scalars, $request);

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
        [$models, $unsent] = $this->resolveModels($action, $input);
        $this->authorize($action, $models, $unsent);

        $subject = $this->subjectOf($action, $models);

        // Every form request that declares the field, not just the first: two
        // requests sharing a name both judge it on submit, so answering from
        // one of them passed a value the submit then refused.
        $declared = false;
        $errors = [];
        foreach ($action->formRequests as $class) {
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $field) {
                    $declared = true;
                    $errors = self::mergeErrors($errors, [
                        $field => $this->validator->errorsFor(
                            $class,
                            $input,
                            $field,
                            $subject,
                            $models,
                            $models,
                            self::unsupplied($action, $models),
                        ),
                    ]);
                }
            }
        }

        // The action's own scalar parameters are fields of the same form: a
        // submit coerces and judges them, so validate-only has to as well.
        $scalar = self::scalarParameters($action)[$field] ?? null;
        if ($scalar !== null) {
            $declared = true;
            [, $found] = $this->validator->parameter(
                $scalar,
                $action->where(),
                $input,
                $subject,
                $models,
                self::unsupplied($action, $models),
            );
            $errors = self::mergeErrors($errors, [$field => $found]);
        }

        // A typo would otherwise return [] and read as "this field is fine",
        // which is the hazard Validator::errorsFor refuses one layer down.
        if (!$declared) {
            throw new \LogicException(
                "Action '{$name}' has no form-request field named '{$field}', nor a parameter of that name.",
            );
        }

        return $errors[$field] ?? [];
    }

    /**
     * Validates every form request and scalar parameter the action takes,
     * and reports what all of them found at once.
     *
     * Throwing on the first form request's errors meant a form split across
     * two showed its second half's mistakes only after the first half was
     * fixed. Precognition looked at no scalar parameter at all, so a value
     * it passed failed the submit.
     *
     * The already-resolved models are handed in, so validation cannot
     * resolve a different row from the one authorized.
     *
     * @param array<string, mixed> $input
     * @param array<string, Model> $models
     * @return array{array<string, FormRequest>, array<string, mixed>} the
     *         requests built and the scalars read, each by parameter
     */
    private function validateInputs(CompiledAction $action, array $input, ?Model $subject, array $models): array
    {
        $built = [];
        $scalars = [];
        $errors = [];
        $unsupplied = self::unsupplied($action, $models);

        foreach ($action->formRequests as $parameter => $class) {
            try {
                $built[$parameter] = $this->validator->validate(
                    $class,
                    $input,
                    $subject,
                    $models,
                    $models,
                    $unsupplied,
                );
            } catch (ValidationException $e) {
                $errors = self::mergeErrors($errors, $e->errors);
            }
        }

        foreach (self::scalarParameters($action) as $name => $parameter) {
            [$value, $found] = $this->validator->parameter(
                $parameter,
                $action->where(),
                $input,
                $subject,
                $models,
                $unsupplied,
            );
            if ($found === []) {
                $scalars[$name] = $value;
            }
            $errors = self::mergeErrors($errors, [$name => $found]);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$built, $scalars];
    }

    /**
     * The model inputs the action declares that this call left out: the
     * optional ones, and required form fields not sent yet.
     *
     * @param array<string, Model> $models
     * @return array<string, class-string<Model>>
     */
    private static function unsupplied(CompiledAction $action, array $models): array
    {
        return array_diff_key($action->allModels(), $models);
    }

    /**
     * The parameters a caller fills with plain values: neither a model, a
     * form request nor the Request.
     *
     * @return array<string, \ReflectionParameter>
     */
    private static function scalarParameters(CompiledAction $action): array
    {
        $scalars = [];
        foreach ((new \ReflectionMethod($action->class, $action->method))->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (isset($action->models[$name]) || isset($action->formRequests[$name])) {
                continue;
            }
            if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                $scalars[$name] = $parameter;
            }
        }

        return $scalars;
    }

    /**
     * One error bag from several, a message once per field however many
     * declarations of the field produced it.
     *
     * @param array<string, list<string>> $into
     * @param array<string, list<string>> $more
     * @return array<string, list<string>>
     */
    private static function mergeErrors(array $into, array $more): array
    {
        foreach ($more as $field => $messages) {
            if ($messages === []) {
                continue;
            }
            $into[$field] = array_values(array_unique([...$into[$field] ?? [], ...$messages]));
        }

        return $into;
    }

    /** @param array<string, Model> $models */
    private function subjectOf(CompiledAction $action, array $models): ?Model
    {
        return $action->subjectArgument === null ? null : ($models[$action->subjectArgument] ?? null);
    }

    /**
     * The models the call names, and the required form-request model fields
     * it left out.
     *
     * "A missing or foreign ID is a 404" is about an id that was SENT: one
     * naming no row in scope, or another tenant's. A form-request field that
     * was not sent at all carries no id; it is that form request's "site is
     * required.", a 422 from step 4. Answering it with a 404 here turned a
     * half-filled form into an HTTP error -- on the full submit, and on
     * Precognition's validate('email') before the user had reached the site
     * select, contradicting Validator::errorsFor.
     *
     * @param array<string, mixed> $input
     * @return array{array<string, Model>, list<string>}
     */
    private function resolveModels(CompiledAction $action, array $input): array
    {
        $models = [];
        $unsent = [];

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

                // Only a form-request field: a model the action takes as its
                // own parameter is the record it acts on, not a form input.
                if (!isset($action->models[$parameter])) {
                    $unsent[] = $parameter;
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

        return [$models, $unsent];
    }

    /**
     * @param array<string, Model> $models
     * @param list<string>         $unsent required form-request models the call left out
     */
    private function authorize(CompiledAction $action, array $models, array $unsent = []): void
    {
        $optional = array_keys($action->optionalModels);

        // A check on a required form field the caller left out has no
        // subject, and is set aside rather than counted as undecided. That
        // is safe only because the call cannot reach the body: the form
        // request that requires the field fails validation with "is
        // required", and Precognition runs no body at all.
        $checks = $action->checks;
        $classLevel = $action->classLevelChecks;
        if ($unsent !== []) {
            $kept = [];
            foreach ($checks as $index => $check) {
                if ($check->argument !== null && \in_array($check->argument, $unsent, true)) {
                    if ($index < $action->classLevelChecks) {
                        $classLevel--;
                    }
                    continue;
                }
                $kept[] = $check;
            }
            $checks = $kept;
        }

        // Every check named an optional input and the call left them all
        // out: nothing would be asked, and the body would still run. Judged
        // for the method's own checks apart from the class's, so a blanket
        // check on the holder cannot stand in for the method's.
        if (Checks::undecided($checks, $classLevel, $models, $optional)) {
            throw ForbiddenException::undecided($action->where());
        }

        foreach ($checks as $check) {
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
     * @param array<string, Model>       $models
     * @param array<string, FormRequest> $requests already validated, by parameter
     * @param array<string, mixed>       $scalars  already coerced and judged, by parameter
     * @return array<string, mixed>
     */
    private function bind(
        CompiledAction $action,
        array $models,
        array $requests,
        array $scalars,
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

            if (isset($requests[$name])) {
                $arguments[$name] = $requests[$name];
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

            if (\array_key_exists($name, $scalars)) {
                // Coerced, not passed through: a client sending name[]=x would
                // otherwise turn a 422 into a TypeError from inside the call.
                $arguments[$name] = $scalars[$name];
                continue;
            }

            throw new \LogicException("{$action->where()}: \${$name} was neither validated nor bound.");
        }

        return $arguments;
    }
}
