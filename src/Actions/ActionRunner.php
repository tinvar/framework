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
use Tinvar\Live\Invalidator;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Render\StaticPages;
use Tinvar\Uploads\Upload;
use Tinvar\Uploads\UploadBinder;
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
 *
 * An `Upload` parameter is input like any other, judged in step 4: the
 * reference the form sent is held to everything its ticket bound it to
 * (UploadBinder), so a Precognition call reports it and claims nothing.
 * The uploads are claimed only once everything else has passed, just before
 * the body, and given back if the body throws.
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
        // Tells every other client listening that the tags were invalidated,
        // once the action's writes are committed (Tinvar\Live).
        private readonly ?Invalidator $live = null,
        // Hands `Upload` parameters the uploads they were called with; an
        // action declaring one is refused without it.
        private readonly ?UploadBinder $uploads = null,
        // Takes the static pages showing what the tags name out of service,
        // once the action's writes are committed.
        private readonly ?StaticPages $static = null,
        // What runs in place of an action's body, by its name, or null for
        // the body -- a test's fake (StandIns::action()); none in production.
        // Asked only once the call has passed resolution, #[Authorize] and
        // validation, and what it returns goes on as the body's result does.
        private readonly ?\Closure $standIn = null,
    ) {}

    /**
     * One answer for every way an upload reference can fail -- expired,
     * claimed, another person's, another record's -- so trying an id
     * learns nothing about it.
     */
    public const string UPLOAD_REFUSED = 'This upload has run out or was not made for this form. Choose the file again.';

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
     * @return class-string|null
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

        // 4. Validate, every form request, scalar parameter and upload
        //    before anything is reported. A Precognition call stops here.
        [$requests, $scalars, $uploads] = $this->validateInputs($action, $input, $subject, $models, $request);

        if ($precognitive) {
            return ActionResult::ok();
        }

        $arguments = $this->bind($action, $models, $requests, $scalars, $request, $uploads);

        // 5. Run the body -- or a test's fake in its place, handed what the
        //    body would have been, uploads claimed for it as for the body --
        //    noting every tenant it ran as: a row it wrote by actAs()ing a
        //    child is the child's, and so are the pages to tell.
        $standIn = $this->standIn === null ? null : ($this->standIn)($name);
        if ($standIn === null) {
            $instance = $this->registry->instanceFor($action);
            $body = static fn (): mixed => $instance->{$action->method}(...$arguments);
        } else {
            $body = static fn (): mixed => $standIn(...$arguments);
        }
        [$result, $tenants] = $this->orm->tenantsEnteredBy(fn (): mixed => $this->hasUploads($uploads)
            ? $this->runWithUploads($uploads, $body)
            : $body());

        if (!$result instanceof ActionResult) {
            throw ActionException::badReturn($action->where(), get_debug_type($result));
        }

        // 6. Invalidate the cached queries the action said it invalidated.
        //    revalidateTags() only ever reached the client, as an
        //    x-invalidated-tags header for its prefetch cache; the server-side
        //    cache was never touched, so a #[Query(cache: '5m')] kept serving
        //    the pre-write answer for the rest of its TTL. With the tenant
        //    tree, so a write made as a child reaches its parents' reads
        //    across the subtree it is part of. In each tenant it ran as, the
        //    one it was called in first. Now, and again once the writes
        //    commit (forgetCached()).
        if ($this->cache !== null && $result->revalidatedTags !== []) {
            $this->forgetCached($this->cache, $result->revalidatedTags, $tenants);
        }

        //    And the clients holding pages that read them -- in other tabs,
        //    for other people -- told: the response's X-Invalidated-Tags
        //    reaches only the client that ran the action.
        $this->live?->invalidateIn($result->revalidatedTags, $tenants);

        //    And the static pages showing them, out of service until they
        //    are built again -- after the commit, so no build in between
        //    reads the old rows and serves them as fresh. Only outside a
        //    tenant: a static page is rendered as no tenant's, and a tenant's
        //    tags name that tenant's rows, so one tenant's action is no
        //    reason to take everyone's pages down.
        if ($this->static !== null && $result->revalidatedTags !== [] && $this->orm->tenant() === '') {
            $static = $this->static;
            $tags = $result->revalidatedTags;
            $this->orm->afterCommit(static function () use ($static, $tags): void {
                // The writes are committed and the answer is the action's: a
                // page that could not be taken down is logged, as a live
                // update that could not be published is, and serves on until
                // its next revalidation.
                try {
                    $static->invalidate($tags);
                } catch (\Throwable $e) {
                    error_log('[tinvar] a static page could not be taken out of service: ' . $e->getMessage());
                }
            });
        }

        // 7. The data boundary. Only Data objects, scalars, lists and maps
        //    leave, in every environment -- an action's value and flash were
        //    reaching the browser unchecked.
        return $result->encoded($this->encoder);
    }

    /**
     * Forgets the tags in the query cache: at once, and -- when the action
     * ran inside a transaction -- again after the outermost one commits.
     *
     * At once, for what reads inside the transaction and sees its writes: a
     * re-render made there must not be handed an entry from before them.
     * After the commit, because a store shared between workers (ApcuStore,
     * PdoStore) is read by requests the transaction does not cover: one can
     * miss between the forget and the commit, read the rows as they were --
     * the writes are not visible to it yet -- and keep that answer for its
     * whole TTL. The second forget drops it. With no transaction open the
     * writes are in already, and the one forget is enough. A rollback
     * forgets nothing more: nothing changed.
     *
     * A failure at once is the action's, as it always was. After a commit
     * it is logged, as a static page that could not be taken down is:
     * thrown there, it would make transaction() throw for writes that are
     * in, and a caller that retries would write them twice.
     *
     * @param list<string> $tags
     * @param list<string> $tenants every tenant the action ran as
     */
    private function forgetCached(QueryCache $cache, array $tags, array $tenants): void
    {
        $tree = $this->orm->tenants();
        $forget = static function () use ($cache, $tags, $tenants, $tree): void {
            foreach ($tenants as $tenant) {
                $cache->forgetTags($tags, $tenant, $tree);
            }
        };

        $forget();

        if ($this->orm->connection()->inTransaction()) {
            $this->orm->afterCommit(static function () use ($forget): void {
                try {
                    $forget();
                } catch (\Throwable $e) {
                    error_log('[tinvar] cached queries could not be invalidated after a commit: ' . $e->getMessage());
                }
            });
        }
    }

    /**
     * Validate without running anything, for Precognition's
     * `validate('email')`.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function validateField(string $name, array $input, string $field, ?Request $request = null): array
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

        // An upload is a field of the form too, judged as a submit judges it
        // -- and claimed by nothing, since nothing runs.
        if (isset($action->uploads[$field])) {
            [, $found] = $this->verifyUpload($action, $field, $input, $models, $request);

            return $found;
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
     * @return array{array<string, FormRequest>, array<string, mixed>, array<string, Upload|null>} the
     *         requests built, the scalars read and the uploads verified, each by parameter
     */
    private function validateInputs(CompiledAction $action, array $input, ?Model $subject, array $models, ?Request $request): array
    {
        $built = [];
        $scalars = [];
        $uploads = [];
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

        foreach (array_keys($action->uploads) as $name) {
            [$upload, $found] = $this->verifyUpload($action, $name, $input, $models, $request);
            if ($found === []) {
                $uploads[$name] = $upload;
            }
            $errors = self::mergeErrors($errors, [$name => $found]);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$built, $scalars, $uploads];
    }

    /**
     * One upload parameter's value: the upload its reference names, held to
     * its ticket's binding; null for an optional one left out.
     *
     * @param array<string, mixed> $input
     * @param array<string, Model> $models
     * @return array{Upload|null, list<string>} the upload, and what was wrong with it
     */
    private function verifyUpload(CompiledAction $action, string $field, array $input, array $models, ?Request $request): array
    {
        if ($this->uploads === null) {
            throw new \LogicException(
                "{$action->where()} takes an upload, and the Kernel was given no Uploads to hand it one with.",
            );
        }

        $reference = $input[$field] ?? null;
        if ($reference === null || $reference === '') {
            return isset($action->optionalUploads[$field]) ? [null, []] : [null, ["{$field} is required."]];
        }

        $upload = $this->uploads->verify($action, $field, $reference, $models, $request);

        return $upload === null ? [null, [self::UPLOAD_REFUSED]] : [$upload, []];
    }

    /** @param array<string, Upload|null> $uploads */
    private function hasUploads(array $uploads): bool
    {
        return array_filter($uploads) !== [];
    }

    /**
     * Runs a body that was handed uploads: claimed for it alone, and in one
     * transaction with its writes.
     *
     * Claimed now, when nothing else can refuse the call, so one upload is
     * attached once however requests race. Given back if the body throws,
     * so the file waits for the customer to try again -- and that is safe
     * only because the body's writes roll back with it: given back after a
     * body that had saved the key on its record, the upload would be
     * pruned as unclaimed and the record left naming a file that is gone.
     * Where the upload store shares the connection the claim rolls back
     * too; a process killed mid-body leaves the upload as it was, or
     * claimed and unused -- a file kept, never a record dangling.
     *
     * @param array<string, Upload|null> $uploads
     * @param \Closure(): mixed $body
     */
    private function runWithUploads(array $uploads, \Closure $body): mixed
    {
        return $this->orm->transaction(function () use ($uploads, $body): mixed {
            $claimed = $this->claim($uploads);

            try {
                return $body();
            } catch (\Throwable $e) {
                // Given back inside the transaction, while the claim still
                // holds the upload. A store on another connection gives it
                // back now; one on this connection gives it back again with
                // the rollback. Given back after the rollback, as it was, the
                // release cleared whatever claim another call had made in
                // between -- the same reference posted twice, the second
                // claiming the moment the first rolled back -- and the upload
                // was pruned from under the record that call committed.
                foreach ($claimed as $upload) {
                    $this->uploads?->release($upload);
                }

                throw $e;
            }
        });
    }

    /**
     * Claims every upload the call was given, or none: one that another
     * call claimed first gives the rest back and refuses this one, as an
     * upload that failed verification does.
     *
     * @param array<string, Upload|null> $uploads
     * @return list<Upload> what was claimed
     */
    private function claim(array $uploads): array
    {
        $claimed = [];
        foreach ($uploads as $field => $upload) {
            if ($upload === null) {
                continue;
            }
            if ($this->uploads?->claim($upload) !== true) {
                foreach ($claimed as $taken) {
                    $this->uploads?->release($taken);
                }

                throw new ValidationException([$field => [self::UPLOAD_REFUSED]]);
            }
            $claimed[] = $upload;
        }

        return $claimed;
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

            if (isset($action->models[$name]) || isset($action->formRequests[$name]) || isset($action->uploads[$name])) {
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
     * @param array<string, Upload|null> $uploads  already verified, by parameter
     * @return array<string, mixed>
     */
    private function bind(
        CompiledAction $action,
        array $models,
        array $requests,
        array $scalars,
        ?Request $request,
        array $uploads = [],
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

            if (isset($action->uploads[$name])) {
                $arguments[$name] = $uploads[$name] ?? ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null);
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
