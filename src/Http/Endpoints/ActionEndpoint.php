<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Actions\ActionRunner;
use Tinvar\Actions\Revalidator;
use Tinvar\Http\EntryContext;
use Tinvar\Http\Flash;
use Tinvar\Http\FlashStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Validation\ValidationException;

/**
 * POST /_action/{name}
 *
 * "Actions are always POST, so no `_method` spoofing." The middleware stack --
 * web, auth, throttle:actions, plus the action's panel stack -- is applied by
 * whoever mounts this; the endpoint itself owns the body limits, the method,
 * and turning a result or a failure into a response.
 *
 * Two kinds of caller, told apart by isFormNavigation(). Script -- <Form>,
 * useForm, a generated action called directly -- gets JSON: the value, the
 * field errors, the re-rendered pages. A browser posting a form itself,
 * before JavaScript has loaded or without it, is navigating, and gets a page:
 * a 303 to wherever the action redirected, or back to the form, with its
 * errors flashed.
 */
final class ActionEndpoint
{
    public const string PREFIX = '/_action/';

    /**
     * @param (\Closure(Request): string)|null $sessionId needed only to verify
     *        the route tokens a client sends for re-rendering
     * @param (\Closure(class-string): Middleware)|null $middlewareResolver builds
     *        an action's own #[Middleware]; required once any action declares one
     * @param list<class-string> $globalMiddleware already run by the stack this
     *        endpoint is mounted in, so not run a second time
     * @param FlashStore|null $flashes where a browser's own form post leaves its
     *        errors for the page it is sent back to; without one, such a post
     *        that fails validation gets the framework's 422 page instead
     * @param EntryContext|null $entry what the action's declarations put around
     *        it -- its tenant, its panel and the panel's stack -- ahead of its
     *        own middleware
     */
    public function __construct(
        private readonly ActionRunner $runner,
        private readonly int $maxBodyBytes = Limits::MAX_BODY_BYTES,
        private readonly ?Revalidator $revalidator = null,
        private readonly ?\Closure $sessionId = null,
        private readonly ?\Closure $middlewareResolver = null,
        private readonly array $globalMiddleware = [],
        private readonly ?FlashStore $flashes = null,
        private readonly ?EntryContext $entry = null,
    ) {}

    /**
     * Whether a request is a browser submitting a form itself -- no script
     * involved -- and so wants a page back rather than JSON.
     *
     * A form's body, from something that asks for HTML and not for JSON.
     * Every script caller asks for JSON; a browser's own submission asks for
     * text/html and never names application/json.
     */
    public static function isFormNavigation(Request $request): bool
    {
        $type = strtolower(trim(explode(';', $request->header('content-type') ?? '', 2)[0]));
        if ($type !== 'application/x-www-form-urlencoded' && $type !== 'multipart/form-data') {
            return false;
        }

        $accept = $request->header('accept') ?? '';

        return str_contains($accept, 'text/html') && !str_contains($accept, 'application/json');
    }

    /**
     * The answer to a browser's own form post: a 303 back to the page it
     * came from, with a flash for that page to read.
     *
     * A flash is only kept where there is a store to keep it in. Without one
     * the redirect still happens -- the write it follows has happened too --
     * and what the flash would have said is not said.
     *
     * @param array<string, mixed> $flash
     */
    public function back(Request $request, array $flash = []): Response
    {
        $response = Response::redirect(Flash::back($request), 303);
        if ($flash === [] || $this->flashes === null) {
            return $response;
        }

        $this->flashes->put($request, $flash);

        return $response->withCookie(Flash::COOKIE, '1', maxAge: Flash::LIFETIME);
    }

    /**
     * How many route tokens one action request will even look at.
     *
     * Distinct from Revalidator::MAX_ROUTES, which bounds RENDERS. This
     * bounds parsing: each token is base64-decoded and JSON-parsed before
     * anything can reject it, so an unbounded list is work before the work.
     */
    public const int MAX_ROUTE_TOKENS = 32;

    /** A route token is a path-sized string, not a payload. */
    public const int MAX_ROUTE_TOKEN_BYTES = 4096;

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    /**
     * Refuses any method but POST, saying which would do. The Kernel asks
     * before the endpoint stack runs, so a GET to an action is a 405 and not
     * whatever the stack would have said first -- a 401 for one that needs a
     * user.
     */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Actions are POST only.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        self::assertMethod($request);

        $name = substr($request->path, \strlen(self::PREFIX));
        if ($name === '') {
            throw new HttpException(404, 'No action named in the path.', 'not_found');
        }

        // The action's own #[Middleware], inside the endpoint stack and around
        // everything that touches the action -- Precognition included, since
        // it resolves models and authorizes too. Compiled and never run, a
        // middleware an author put on an action was silently ignored.
        //
        // Its tenant and its panel's stack ahead of that, as a page's are:
        // model arguments are resolved scoped to the tenant, and "the full
        // middleware stack of the action's panel (guard, MFA, network
        // allowlist, email verification)" is step one of every call.
        $entry = [];
        if ($this->entry !== null) {
            $panels = $this->runner->panelsFor($name);
            $entry = $this->entry->stackFor(
                $this->runner->tenantSourceFor($name),
                $panels === null ? null : $this->entry->panelFor(...$panels),
            );
        }
        $own = array_values(array_diff($this->runner->middlewareFor($name), $this->globalMiddleware));
        if ($entry === [] && $own === []) {
            return $this->dispatch($request, $name, $rawBody);
        }
        $resolver = $this->middlewareResolver;
        if ($own !== [] && $resolver === null) {
            throw new \LogicException(
                "Action {$name} declares #[Middleware] but the endpoint was given no middleware resolver.",
            );
        }

        return new Pipeline([...$entry, ...($resolver === null ? [] : array_map($resolver, $own))])
            ->run($request, fn (Request $r): Response => $this->dispatch($r, $name, $rawBody));
    }

    private function dispatch(Request $request, string $name, string $rawBody): Response
    {
        $input = Limits::input($request, $rawBody, $this->maxBodyBytes);

        // "Precognition / Precognition-Validate-Only": validate without
        // running the body, optionally one field at a time.
        $precognitive = $request->header('precognition') === 'true';
        $only = $request->header('precognition-validate-only');

        try {
            if ($precognitive && $only !== null && $only !== '') {
                $fields = explode(',', $only);

                // The header is client-supplied and each field re-runs model
                // resolution and authorization, which the body limit does not
                // bound.
                if (\count($fields) > Limits::MAX_PRECOGNITION_FIELDS) {
                    throw new HttpException(
                        400,
                        'Too many fields named in Precognition-Validate-Only.',
                        'bad_request',
                    );
                }

                $errors = [];
                foreach ($fields as $field) {
                    $field = trim($field);

                    try {
                        $found = $this->runner->validateField($name, $input, $field, $request);
                    } catch (\LogicException) {
                        // validateField() refuses an unknown field rather than
                        // returning [], which would read as "this field is
                        // fine" -- right for an author typo, wrong for a name
                        // a client chose. Here it is bad input, not a bug, and
                        // the exception message quotes the client's own text
                        // back, which the error-channel rule forbids.
                        throw new HttpException(
                            422,
                            'Precognition-Validate-Only names a field this action does not have.',
                            'unprocessable',
                        );
                    }

                    if ($found !== []) {
                        $errors[$field] = $found;
                    }
                }

                return $errors === []
                    ? self::json(204, [])
                    : self::json(422, ['errors' => $errors]);
            }

            // The token list is transport, not input. Left in, it reached
            // every form request and Data object as a user-supplied field,
            // collided with any field genuinely named _routes, and counted
            // against the body budget alongside real data.
            $routeTokens = $input['_routes'] ?? [];
            unset($input['_routes']);

            // So is the hidden CSRF field a form posted without script
            // carries: VerifyCsrfToken has read it from the body already.
            unset($input['_token']);

            $result = $this->runner->run($name, $input, $precognitive, $request);
        } catch (ValidationException $e) {
            if (self::isFormNavigation($request)) {
                // "Without JavaScript, fall back to redirect-back with
                // flashed errors." With nowhere to flash them, the 422 goes
                // to the exception handler, whose page at least says so.
                if ($this->flashes === null) {
                    throw $e;
                }

                return $this->back($request, ['form' => [
                    'action' => $name,
                    'errors' => $e->errors,
                    'old' => Flash::old($input),
                ]]);
            }

            // "With JavaScript, a failed action returns errors with the
            // payload (no redirect)."
            return self::json(422, ['errors' => $e->errors]);
        }

        if ($result->isRedirect()) {
            // 303 after a write, so a reload does not repeat it. No body:
            // ActionResult refuses a flash or a value on a redirect, which
            // this response would have nowhere to put. A client-router call
            // reads it as 409 + X-Redirect; the Kernel translates it.
            return Response::redirect((string) $result->redirect, 303)
                ->withHeader('x-invalidated-tags', implode(',', $result->revalidatedTags));
        }

        if (self::isFormNavigation($request)) {
            // A browser's own post is answered with a page, never with JSON
            // it would show as text. The page it came from is rendered fresh,
            // so there is nothing to re-render for it here, and the value is
            // for script; the flash is what the person should see.
            return $this->back($request, $result->flash === [] ? [] : ['flash' => $result->flash])
                ->withHeader('x-invalidated-tags', implode(',', $result->revalidatedTags));
        }

        return self::json($result->status, [
            'value' => $result->value,
            'flash' => $result->flash,
            'revalidated' => $result->revalidatedRoutes,
            // "The response carries a fresh RSC payload for affected
            // mounted routes." Absent for any route whose pipeline denied,
            // whose token this server will not act on, or that the client
            // was not holding -- the write stands either way.
            'payloads' => $this->rerender($result->revalidatedRoutes, $routeTokens, $request),
        ])
            // "an action's response lists the server-side query tags it
            // invalidated, and the client flushes matching prefetch entries
            // automatically."
            ->withHeader('x-invalidated-tags', implode(',', $result->revalidatedTags));
    }

    /** @param array<string, mixed> $payload */
    private static function json(int $status, array $payload): Response
    {
        return Response::make(
            $status,
            $status === 204 ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            [
                'content-type' => 'application/json',
                'cache-control' => 'private, no-store',
                // The one endpoint of the four that omitted it. Harmless
                // beside no-store, and the spec asks for the pair together.
                'vary' => 'Cookie',
            ],
        );
    }

    /**
     * Re-renders the mounted routes this action invalidated.
     *
     * @param list<string> $invalidated
     * @return array<string, string>
     */
    private function rerender(array $invalidated, mixed $tokens, Request $request): array
    {
        if ($this->revalidator === null || $invalidated === []) {
            return [];
        }

        // What the action named is checked before anything about the
        // client's tokens: a target that refers to no route is the action's
        // bug, and it must not pass in silence because this client happened
        // to send no token.
        $this->revalidator->routesFor($invalidated);

        if ($this->sessionId === null || !\is_array($tokens) || !array_is_list($tokens)) {
            return [];
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (mixed $t): bool => \is_string($t) && $t !== ''
                && \strlen($t) <= self::MAX_ROUTE_TOKEN_BYTES,
        ));

        // The cap lives in the Revalidator, which is the only place that
        // can apply it after deduplication -- here it bounded tokens
        // examined rather than renders performed. This bound is on how much
        // the endpoint will PARSE, which is a different and also necessary
        // limit.
        return $this->revalidator->rerender(
            $invalidated,
            \array_slice($tokens, 0, self::MAX_ROUTE_TOKENS),
            $request,
            $this->sessionId,
        );
    }
}
