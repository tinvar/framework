<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\Encoder;
use Tinvar\Data\SharedTags;
use Tinvar\Endpoints\UnknownField;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Live\Invalidator;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Render\StaticPages;
use Tinvar\Support\Reflected;
use Tinvar\Uploads\Upload;
use Tinvar\Uploads\UploadBinder;
use Tinvar\Uploads\UploadList;
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
    /** What the tags an action's result names do on the server (ADR 0075). */
    private readonly TagInvalidation $invalidation;

    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly Orm $orm,
        private readonly Gate $gate,
        private readonly Validator $validator,
        private readonly Encoder $encoder = new Encoder(),
        ?QueryCache $cache = null,
        // Tells every other client listening that the tags were invalidated,
        // once the action's writes are committed (Tinvar\Live).
        ?Invalidator $live = null,
        // Hands `Upload` parameters the uploads they were called with; an
        // action declaring one is refused without it.
        private readonly ?UploadBinder $uploads = null,
        // Takes the static pages showing what the tags name out of service,
        // once the action's writes are committed.
        ?StaticPages $static = null,
        // What runs in place of an action's body, by its name, or null for
        // the body -- a test's fake (StandIns::action()); none in production.
        // Asked only once the call has passed resolution, #[Authorize] and
        // validation, and what it returns goes on as the body's result does.
        private readonly ?\Closure $standIn = null,
        // Moves the versions once-shared holds are bound to, so a hold on a
        // value the action changed is believed by no browser, not only the
        // one its response reaches (ADR 0074).
        ?SharedTags $sharedTags = null,
    ) {
        $this->invalidation = new TagInvalidation($orm, $cache, $live, $static, $sharedTags);
    }

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
     * @param (\Closure(ActionResult): void)|null $answerable throws for a result
     *        that cannot be answered -- a redirect to where none may go, a
     *        route that is no page -- once the body has returned and its tags
     *        are invalidated: inside the action's transaction where it runs in
     *        one, so a refusal leaves nothing written (ADR 0107)
     */
    public function run(string $name, array $input, bool $precognitive = false, ?Request $request = null, ?\Closure $answerable = null): ActionResult
    {
        $action = $this->registry->get($name);

        // 2. Resolve model arguments, scoped to the tenant.
        [$models, $unsent] = $this->resolveModels($action, $input);

        // 3. Authorize, against the resolved models.
        $setAside = $this->authorize($action, $models, $unsent);

        $subject = $this->subjectOf($action, $models);

        // A check set aside for a model field left out: "is required", and
        // nothing else -- no rule has been cleared to answer this caller.
        if ($setAside !== []) {
            throw new ValidationException($this->requiredOnly($action, $input, $setAside, $subject, $models));
        }

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

        $answered = function () use ($action, $uploads, $body, $answerable): ActionResult {
            [$result, $tenants] = $this->orm->tenantsEnteredBy(fn (): mixed => $this->hasUploads($uploads)
                ? $this->runWithUploads($uploads, $body)
                : $body());

            if (!$result instanceof ActionResult) {
                throw ActionException::badReturn($action->where(), get_debug_type($result));
            }

            // 6. Invalidate what the action said it invalidated: the cached
            //    queries, at once and again once the writes commit -- the
            //    server-side cache was once never touched, so a
            //    #[Query(cache: '5m')] kept serving the pre-write answer for
            //    the rest of its TTL; every client listening, in other tabs
            //    and for other people, once the writes commit -- the
            //    response's X-Invalidated-Tags reaches only the client that
            //    ran it; every hold on a once-shared value carrying one, by
            //    any browser; and the static pages showing them. In each
            //    tenant it ran as, the one it was called in first, so a write
            //    made as a child reaches its parents' reads across the
            //    subtree (TagInvalidation).
            $this->invalidation->invalidateIn($result->revalidatedTags, $tenants);

            // Then whether it can be answered at all. After the tags, so an
            // action whose writes are in -- one outside a transaction -- still
            // has them announced when its redirect is refused. Inside its
            // transaction, a refusal rolls back the writes with it and the
            // announcements that wait on the commit.
            if ($answerable !== null) {
                $answerable($result);
            }

            // 7. The data boundary. Only Data objects, scalars, lists and
            //    maps leave, in every environment -- an action's value and
            //    flash were reaching the browser unchecked.
            return $result->encoded($this->encoder);
        };

        // The body, its tags and the check of its answer in one transaction,
        // where the action asked for one (ADR 0107): a location() off every
        // allowed origin was refused after the writes it followed, and they
        // stayed.
        return $action->transaction ? $this->orm->transaction($answered) : $answered();
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
        $setAside = $this->authorize($action, $models, $unsent);

        $subject = $this->subjectOf($action, $models);

        // As run() answers: the field left out is required, and every other
        // field waits for it, since none has been cleared to be judged.
        if ($setAside !== []) {
            if (\in_array($field, $setAside, true)) {
                return $this->requiredOnly($action, $input, [$field], $subject, $models)[$field];
            }
            if (!$this->declares($action, $field)) {
                throw new UnknownField(
                    "Action '{$name}' has no form-request field named '{$field}', nor a parameter of that name.",
                );
            }

            return [];
        }

        // Every form request that declares the field, not just the first: two
        // requests sharing a name both judge it on submit, so answering from
        // one of them passed a value the submit then refused.
        $declared = false;
        $errors = [];
        foreach ($action->formRequests as $class) {
            foreach (Reflected::constructorParameters($class) as $parameter) {
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
            throw new UnknownField(
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
     * @return array{array<string, FormRequest>, array<string, mixed>, array<string, Upload|UploadList|null>} the
     *         requests built, the scalars read and the uploads verified, each by parameter
     */
    private function validateInputs(CompiledAction $action, array $input, ?Model $subject, array $models, ?Request $request): array
    {
        $built = [];
        $scalars = [];
        $uploads = [];
        $errors = [];
        $refused = [];
        $unsupplied = self::unsupplied($action, $models);

        // The uploads first: one inside a form request is handed to it as
        // the upload its reference named, verified here, never read from
        // the input by the validator.
        foreach (array_keys($action->uploads) as $name) {
            [$upload, $found] = $this->verifyUpload($action, $name, $input, $models, $request);
            if ($found === []) {
                $uploads[$name] = $upload;
            } else {
                $refused[$name] = $found;
            }
        }

        foreach ($action->formRequests as $parameter => $class) {
            try {
                $built[$parameter] = $this->validator->validate(
                    $class,
                    $input,
                    $subject,
                    [...$models, ...array_filter($uploads)],
                    $models,
                    $unsupplied,
                );
            } catch (ValidationException $e) {
                // An upload refused is said once, in its own words, not
                // again as "required" by the form it is a field of.
                $errors = self::mergeErrors($errors, array_diff_key($e->errors, $refused));
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

        $errors = self::mergeErrors($errors, $refused);

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
     * @return array{Upload|UploadList|null, list<string>} the upload or uploads, and what was wrong with them
     */
    private function verifyUpload(CompiledAction $action, string $field, array $input, array $models, ?Request $request): array
    {
        if ($this->uploads === null) {
            throw new \LogicException(
                "{$action->where()} takes an upload, and the Kernel was given no Uploads to hand it one with.",
            );
        }

        $reference = $input[$field] ?? null;
        $declared = $action->uploads[$field];
        if ($reference === null || $reference === '' || $reference === []) {
            return isset($action->optionalUploads[$field]) ? [null, []] : [null, ["{$field} is required."]];
        }

        if ($declared->maxFiles === 1) {
            $upload = $this->uploads->verify($action, $field, $reference, $models, $request);

            return $upload === null ? [null, [self::UPLOAD_REFUSED]] : [$upload, []];
        }

        // A list: each reference once, no more than declared, and every one
        // held to its ticket's binding as a single upload is -- one that is
        // not refuses them all.
        if (!\is_array($reference) || !array_is_list($reference)) {
            return [null, [self::UPLOAD_REFUSED]];
        }
        if (\count($reference) > $declared->maxFiles) {
            return [null, ["{$field} takes at most {$declared->maxFiles} files."]];
        }
        if (\count(array_unique(array_map(static fn (mixed $one): string => \is_string($one) ? $one : '', $reference))) !== \count($reference)) {
            return [null, [self::UPLOAD_REFUSED]];
        }
        $files = [];
        foreach ($reference as $one) {
            $upload = $this->uploads->verify($action, $field, $one, $models, $request);
            if ($upload === null) {
                return [null, [self::UPLOAD_REFUSED]];
            }
            $files[] = $upload;
        }

        return [new UploadList($files), []];
    }

    /** @param array<string, Upload|UploadList|null> $uploads */
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
     * @param array<string, Upload|UploadList|null> $uploads
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
     * @param array<string, Upload|UploadList|null> $uploads
     * @return list<Upload> what was claimed
     */
    private function claim(array $uploads): array
    {
        $claimed = [];
        foreach ($uploads as $field => $given) {
            foreach ($given instanceof UploadList ? $given->files : ($given === null ? [] : [$given]) as $upload) {
                if ($this->uploads?->claim($upload) !== true) {
                    foreach ($claimed as $taken) {
                        $this->uploads?->release($taken);
                    }

                    throw new ValidationException([$field => [self::UPLOAD_REFUSED]]);
                }
                $claimed[] = $upload;
            }
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
        foreach (Reflected::methodParameters($action->class, $action->method) as $parameter) {
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
     * What a call is told while a check on a model field it left out is set
     * aside: each such field's "is required", from the form requests that
     * declare it, judged with the database rules standing down. Nothing
     * else is judged, so no Exists or Unique -- nor any rule -- answers a
     * caller whose check on that field has not been asked. A field that
     * somehow drew no error is refused as undecided: the call would
     * otherwise reach the body with a check unasked.
     *
     * @param array<string, mixed> $input
     * @param non-empty-list<string> $fields
     * @param array<string, Model> $models
     * @return non-empty-array<string, non-empty-list<string>>
     */
    private function requiredOnly(CompiledAction $action, array $input, array $fields, ?Model $subject, array $models): array
    {
        $validator = $this->validator->withoutLookups();
        $unsupplied = self::unsupplied($action, $models);
        $errors = [];
        foreach ($fields as $field) {
            foreach ($action->formRequests as $class) {
                foreach (Reflected::constructorParameters($class) as $parameter) {
                    if ($parameter->getName() === $field) {
                        $errors = self::mergeErrors($errors, [
                            $field => $validator->errorsFor($class, $input, $field, $subject, $models, $models, $unsupplied),
                        ]);
                    }
                }
            }
            if (($errors[$field] ?? []) === []) {
                throw ForbiddenException::undecided($action->where());
            }
        }

        /** @var non-empty-array<string, non-empty-list<string>> $errors */
        return $errors;
    }

    /** Whether the action has a field of this name: a form request's, an upload or a parameter. */
    private function declares(CompiledAction $action, string $field): bool
    {
        if (isset($action->uploads[$field]) || isset(self::scalarParameters($action)[$field])) {
            return true;
        }
        foreach ($action->formRequests as $class) {
            foreach (Reflected::constructorParameters($class) as $parameter) {
                if ($parameter->getName() === $field) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, Model> $models
     * @param list<string>         $unsent required form-request models the call left out
     * @return list<string> the fields whose checks were set aside, because the call left them out
     */
    private function authorize(CompiledAction $action, array $models, array $unsent = []): array
    {
        $optional = array_keys($action->optionalModels);

        // A check on a required form field the caller left out has no
        // subject, and is set aside rather than counted as undecided. That
        // is safe only because the call cannot reach the body, nor learn
        // anything a rule would say: it is answered with that field's "is
        // required" alone (requiredOnly()), and Precognition runs no body
        // at all.
        $checks = $action->checks;
        $classLevel = $action->classLevelChecks;
        $setAside = [];
        if ($unsent !== []) {
            $kept = [];
            foreach ($checks as $index => $check) {
                if ($check->argument !== null && \in_array($check->argument, $unsent, true)) {
                    if ($index < $action->classLevelChecks) {
                        $classLevel--;
                    }
                    $setAside[$check->argument] = true;
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

        return array_keys($setAside);
    }

    /**
     * @param array<string, Model>       $models
     * @param array<string, FormRequest> $requests already validated, by parameter
     * @param array<string, mixed>       $scalars  already coerced and judged, by parameter
     * @param array<string, Upload|UploadList|null> $uploads  already verified, by parameter
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

        foreach (Reflected::methodParameters($action->class, $action->method) as $parameter) {
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
