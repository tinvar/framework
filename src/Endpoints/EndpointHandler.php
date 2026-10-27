<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
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
 * in, so it runs first, then the authentication the stack could not ask
 * before it, then the tenant and the panel -- whose membership check needs
 * to know who the caller is.
 */
final class EndpointHandler
{
    /** The request attribute carrying the matched endpoint, for the stack's authentication to read. */
    public const string MATCHED = 'tinvar.endpoint';

    /**
     * @param \Closure(class-string): Middleware $middlewareResolver builds an
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

        $stack = $endpoint->sessionless
            ? [...$own, new RequireAuthentication($this->auth, self::entryPoint(...)), ...$entry]
            : [...$entry, ...$own];

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
