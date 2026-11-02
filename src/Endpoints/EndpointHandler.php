<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

use Tinvar\Attributes\Endpoint;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Validation\ValidationException;

/**
 * An endpoint's request, from the Kernel's stack to its response.
 *
 * The Kernel matches the path (EndpointRegistry::match()), refuses a
 * method nothing there answers with a 405, and runs its stack -- the
 * global middleware, CSRF, the throttle and authentication, or for a
 * #[WithoutSession] endpoint the throttle alone -- around handle(). This
 * puts the endpoint's own declarations inside that: its tenant, its panel
 * and the panel's stack, as an action's, then its #[Middleware], then the
 * runner.
 *
 * A #[WithoutSession] endpoint's own middleware is what signs its caller
 * in -- or, for one that requires API token scopes, the token's (ADR
 * 0151), ahead of its own middleware -- so it runs first, then the authentication the stack could not ask
 * before it, then the tenant and the panel -- whose membership check needs
 * to know who the caller is.
 *
 * An endpoint's own #[Throttle] bucket (ADR 0074) is spent here, inside the
 * stack's `throttle:endpoints`, by address first and by account once there
 * is one: for a sessionless endpoint after its own middleware signed the
 * caller in, which is also where its share of `throttle:endpoints` by
 * account is spent, since the stack ran before anyone was signed in.
 */
final class EndpointHandler
{
    /** The request attribute carrying the matched endpoint, for the stack's authentication to read. */
    public const string MATCHED = 'tinvar.endpoint';

    /**
     * @param \Closure(string): Middleware $middlewareResolver builds an
     *        endpoint's own #[Middleware]
     * @param list<class-string> $globalMiddleware already run by the stack, so
     *        not run a second time
     */
    public function __construct(
        public readonly EndpointRegistry $registry,
        private readonly EndpointRunner $runner,
        private readonly AuthContext $auth,
        private readonly \Closure $middlewareResolver,
        private readonly array $globalMiddleware = [],
        private readonly ?EntryContext $entry = null,
        private readonly int $maxBodyBytes = Limits::MAX_BODY_BYTES,
        private readonly int $maxJsonDepth = Limits::MAX_JSON_DEPTH,
        private readonly ?Throttle $throttle = null,
        // `throttle:endpoints` a minute, for the account pass a sessionless
        // endpoint's stack could not run; 0 for none.
        private readonly int $endpointsLimit = 0,
        // An endpoint that takes API tokens (#[RequiresScope], ADR 0151):
        // the middleware that signs a token's user in, run where a
        // sessionless endpoint's own middleware would sign its caller in;
        // and, for each such endpoint, the one that holds the token to its
        // scopes and to the tenant, panel and host the entry checks put it
        // in, run after them. Both the API tokens' module, handed in by the
        // Kernel; neither, and such an endpoint is refused when called.
        private readonly ?Middleware $tokenSignIn = null,
        private readonly ?\Closure $tokenHold = null,
    ) {
        Limits::assertConfigurable($maxBodyBytes, $maxJsonDepth);
    }

    /** The endpoint a request was matched to, by the Kernel; null for none. */
    public static function matched(Request $request): ?CompiledEndpoint
    {
        $endpoint = $request->attribute(self::MATCHED);

        return $endpoint instanceof CompiledEndpoint ? $endpoint : null;
    }

    /**
     * @param array<string, string> $parameters the path's, as matched
     */
    public function handle(Request $request, CompiledEndpoint $endpoint, array $parameters, string $rawBody = ''): Response
    {
        $entry = $this->entry === null
            ? []
            : $this->entry->stackFor($endpoint->tenantFrom, $this->entry->panelFor($endpoint->panels, $endpoint->class));
        $own = array_map(
            $this->middlewareResolver,
            $endpoint->sessionless ? $endpoint->middleware : array_values(array_diff($endpoint->middleware, $this->globalMiddleware)),
        );

        $tokens = [[], []];
        if ($endpoint->takesApiTokens()) {
            if ($this->tokenSignIn === null || $this->tokenHold === null) {
                throw new \LogicException("{$endpoint->where()} requires API token scopes, and the Kernel was given no ApiAccess to read a token with.");
            }
            /** @var Middleware $hold */
            $hold = ($this->tokenHold)($endpoint);
            $tokens = [[$this->tokenSignIn], [$hold]];
        }

        $stack = $endpoint->sessionless
            ? [
                ...$this->bucket($endpoint, byAddress: true),
                ...$tokens[0],
                ...$own,
                new RequireAuthentication($this->auth, self::entryPoint(...)),
                ...$this->accountShareOfEndpoints(),
                ...$this->bucket($endpoint, byAddress: false),
                ...$entry,
                ...$tokens[1],
            ]
            : [
                ...$this->bucket($endpoint, byAddress: true),
                ...$this->bucket($endpoint, byAddress: false),
                ...$entry,
                ...$own,
            ];

        $response = new Pipeline($stack)->run(
            $request,
            fn (Request $passed): Response => $this->dispatch($passed, $endpoint, $parameters, $rawBody),
        );

        return $this->finish($request, $endpoint, $response);
    }

    /**
     * The class and method a matched request is for, so #[PublicAccess] is
     * read off it; null for a request matched to nothing, which is not
     * public.
     *
     * @return array{0: class-string, 1: string}|null
     */
    public static function entryPoint(Request $request): ?array
    {
        $endpoint = self::matched($request);
        if ($endpoint === null) {
            return null;
        }

        /** @var class-string $class */
        $class = $endpoint->class;

        return [$class, $endpoint->method];
    }

    /** @param array<string, string> $parameters */
    private function dispatch(Request $request, CompiledEndpoint $endpoint, array $parameters, string $rawBody): Response
    {
        // A GET's input is its query string; anything else's, its body,
        // under the limits /_action and /_query take theirs under.
        if (\in_array($request->method, ['GET', 'HEAD'], true)) {
            $input = $request->query;
        } else {
            $input = Limits::input($request, $rawBody, $this->maxBodyBytes, $this->maxJsonDepth);
            // The hidden field a form posted without script carries: CSRF
            // has read it already, and it is no input of the endpoint's.
            unset($input['_token']);
        }

        /** @var array<string, mixed> $input */
        if ($request->header('precognition') === 'true') {
            return $this->precognition($request, $endpoint, $parameters, $input);
        }

        try {
            return $this->runner->run($endpoint, $parameters, $input, $request);
        } catch (ValidationException $e) {
            return Response::make(
                422,
                json_encode(['errors' => $e->errors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ['content-type' => 'application/json'],
            );
        }
    }

    /**
     * "Precognition / Precognition-Validate-Only", as an action answers it
     * (ADR 0074): the models resolved, every check, the form requests -- or
     * only the fields named -- and no body. 204 when the input would do, 422
     * with the errors when not. Behind the same stack as the call itself,
     * CSRF and both throttles included, so asking costs what calling does.
     *
     * @param array<string, string> $parameters
     * @param array<string, mixed>  $input
     */
    private function precognition(Request $request, CompiledEndpoint $endpoint, array $parameters, array $input): Response
    {
        $only = null;
        $named = $request->header('precognition-validate-only');
        if ($named !== null && trim($named) !== '') {
            $only = array_values(array_unique(array_map(trim(...), explode(',', $named))));
            // Client-supplied, and each field re-runs its rules, which the
            // body limit does not bound.
            if (\count($only) > Limits::MAX_PRECOGNITION_FIELDS) {
                throw new HttpException(400, 'Too many fields named in Precognition-Validate-Only.', 'bad_request');
            }
        }

        try {
            $errors = $this->runner->precognition($endpoint, $parameters, $input, $only);
        } catch (UnknownField) {
            // A name the client chose, not an author's typo: bad input, and
            // not quoted back.
            throw new HttpException(422, 'Precognition-Validate-Only names a field this endpoint does not have.', 'unprocessable');
        }

        return $errors === []
            ? Response::make(204, '', ['precognition' => 'true'])
            : Response::make(
                422,
                json_encode(['errors' => $errors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ['content-type' => 'application/json', 'precognition' => 'true'],
            );
    }

    /**
     * The endpoint's own bucket, one pass of it; nothing for an endpoint
     * that names none.
     *
     * @return list<Middleware>
     */
    private function bucket(CompiledEndpoint $endpoint, bool $byAddress): array
    {
        if ($endpoint->throttle === null) {
            return [];
        }
        if ($this->throttle === null) {
            // The Kernel refuses this when it is built; a handler built
            // otherwise refuses it here rather than leave the bucket unspent.
            throw new \LogicException("{$endpoint->where()} names the throttle bucket '{$endpoint->throttle->bucket}', and nothing counts throttles.");
        }

        return [new ThrottleRequests($this->throttle, $endpoint->throttle->counter(), $endpoint->throttle->perMinute, byAddress: $byAddress)];
    }

    /**
     * `throttle:endpoints` by account, for a sessionless endpoint: the
     * stack's pass ran before its own middleware signed anyone in, so it
     * charged the address alone.
     *
     * @return list<Middleware>
     */
    private function accountShareOfEndpoints(): array
    {
        return $this->throttle === null || $this->endpointsLimit <= 0
            ? []
            : [new ThrottleRequests($this->throttle, 'endpoints', $this->endpointsLimit, byAddress: false)];
    }

    /**
     * An OPTIONS preflight's answer, or null for one that is not answered --
     * the 405 every other OPTIONS is (ADR 0074).
     *
     * Answered only when all of it holds: the request names an Origin and
     * a method an endpoint may declare; the endpoint at the path answering
     * that method declares #[Cors]; the Origin is one it names, byte for
     * byte; and every header the preflight asks to send is one it allows.
     * Nothing runs for it -- no session, no check, no body -- and it says
     * only what that endpoint's #[Cors] already says to that origin.
     */
    public function preflight(Request $request): ?Response
    {
        $origin = $request->header('origin');
        $method = $request->header('access-control-request-method');
        // HEAD with GET, as everywhere: a HEAD sending a header of its own
        // is preflighted too.
        if ($origin === null || $method === null || !\in_array($method, [...Endpoint::METHODS, 'HEAD'], true)) {
            return null;
        }

        $matched = $this->registry->matchAs($request, $method);
        $endpoint = $matched['endpoint'] ?? null;
        $cors = $endpoint?->cors;
        if ($endpoint === null || $cors === null || !$cors->allows($origin)
            || !$cors->allowsHeaders($request->header('access-control-request-headers'))) {
            return null;
        }

        $headers = [
            'access-control-allow-origin' => $origin,
            'access-control-allow-methods' => implode(', ', $endpoint->allowed()),
            'access-control-allow-headers' => implode(', ', $cors->headers),
            'access-control-max-age' => (string) $cors->maxAge,
            'vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            'cache-control' => 'private, no-store',
        ];
        if ($cors->credentials) {
            $headers['access-control-allow-credentials'] = 'true';
        }

        return Response::make(204, '', $headers);
    }

    /**
     * An endpoint's response -- its answer or its refusal -- with what its
     * #[Cors] tells the origin that asked (ADR 0074): `Vary: Origin` always,
     * since what it carries depends on the Origin, and the Origin echoed
     * back only when it is one the endpoint names. Never `*`, and never an
     * Origin nobody named. Nothing for an endpoint that declares none.
     */
    public static function crossOrigin(Request $request, CompiledEndpoint $endpoint, Response $response): Response
    {
        $cors = $endpoint->cors;
        if ($cors === null) {
            return $response;
        }

        $response = $response->withAddedHeader('vary', 'Origin');
        $origin = $request->header('origin');
        if ($origin === null || !$cors->allows($origin)) {
            return $response;
        }

        $response = $response
            ->withHeader('access-control-allow-origin', $origin)
            // What a caller paces itself by, and when a 429 says to retry.
            ->withHeader('access-control-expose-headers', 'retry-after, x-ratelimit-limit, x-ratelimit-remaining, x-ratelimit-reset');

        return $cors->credentials ? $response->withHeader('access-control-allow-credentials', 'true') : $response;
    }

    /**
     * What every endpoint's response carries unless it says otherwise:
     * kept by no shared cache and no browser cache -- it was answered for
     * whoever asked, by their session or their token -- read as nothing but
     * the type it names, and loaded by no other origin's page. And a HEAD
     * is answered with a GET's headers and no body, its stream never run.
     */
    private function finish(Request $request, CompiledEndpoint $endpoint, Response $response): Response
    {
        $defaults = [
            'cache-control' => 'private, no-store',
            'x-content-type-options' => 'nosniff',
            'cross-origin-resource-policy' => 'same-origin',
        ];
        foreach ($defaults as $name => $value) {
            if ($response->header($name) === null) {
                $response = $response->withHeader($name, $value);
            }
        }

        // An endpoint that needs someone signed in answered for them alone:
        // a Cache-Control of its own that would let a shared cache keep it
        // -- `public`, or a max-age with neither `private` nor `no-store` --
        // is replaced, as "authenticated responses send private, no-store"
        // asks. Only a #[PublicAccess] endpoint chooses its own caching.
        $cache = strtolower((string) $response->header('cache-control'));
        if (!$endpoint->publicAccess && !str_contains($cache, 'private') && !str_contains($cache, 'no-store')) {
            $response = $response->withHeader('cache-control', 'private, no-store');
        }
        if (!$endpoint->sessionless) {
            $response = $response->withAddedHeader('vary', 'Cookie');
        }

        if ($request->method !== 'HEAD') {
            return $response;
        }

        return Response::make($response->status, '', $response->headers())->withCookiesOf($response);
    }

    /**
     * A 405 for a path whose endpoints answer other methods, naming them.
     *
     * @param list<string> $allow
     */
    public static function notAllowed(array $allow): HttpException
    {
        return new HttpException(405, 'No endpoint here answers this method.', 'method_not_allowed', ['allow' => implode(', ', $allow)]);
    }
}
