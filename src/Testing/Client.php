<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Attributes\PublicAccess;
use Tinvar\Auth\Authenticatable;
use Tinvar\Data\Encoder;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\ExceptionHandler;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Model;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderResult;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Support\DevMode;

/**
 * Who a test is, and what it asks the application for.
 *
 * Immutable: actingAs(), asTenant() and the rest return a new client, so a
 * test that signs in for one call has not signed in for the next, and two
 * clients never share a user by accident.
 *
 * Three kinds of call, the spec's layers:
 *
 *   - get() and navigate(): a page through the whole PHP request path --
 *     global and route middleware, the panel's stack, every #[Authorize],
 *     each loader -- stopping where the renderer would start, with the data
 *     it would have been handed recorded instead (PageResponse).
 *   - render(): the same request through the application's real renderer
 *     (RenderedPage), from the renderer pool.
 *   - query() and action(): an entry point called in PHP, without the
 *     transport: no session, CSRF or throttle, everything from model
 *     resolution on as a real call meets it.
 *
 * A page request is a browser's, so it is someone only through the session
 * the application makes for them (TestableApplication::signedIn()), and is
 * in whichever tenant and panel its route declares. A direct call is made
 * in PHP, so the user, tenant and panel are entered around it, as a job's
 * are.
 */
final readonly class Client
{
    /**
     * @param array<string, string> $headers sent with every request
     * @param array<string, string> $cookies sent with every request
     * @param list<string>|null $precognition fields to validate alone, [] for a whole
     *        validate-only call, null for an ordinary one
     */
    public function __construct(
        public TestableApplication $app,
        public ?RendererPool $renderer = null,
        private ?Authenticatable $user = null,
        private string $epoch = '',
        private ?string $tenant = null,
        private ?string $panel = null,
        private array $headers = [],
        private array $cookies = [],
        private ?array $precognition = null,
        private bool $leakCheck = true,
    ) {
        // A client signs anyone in without a password and calls entry points
        // past their transport. Fail closed where the environment says this
        // is production, as dev mode does (DevMode).
        $marker = DevMode::productionMarker(getenv());
        if ($marker !== null) {
            throw new \LogicException("The testing layer is refused: {$marker}=production.");
        }
    }

    /**
     * As $user, at their auth epoch: a session made for them on a page
     * request, the current user around a direct call.
     */
    #[\NoDiscard]
    public function actingAs(?Authenticatable $user, string $epoch = ''): self
    {
        return clone($this, ['user' => $user, 'epoch' => $user === null ? '' : $epoch]);
    }

    /** A direct call in tenant $tenant; a page request is in its route's. */
    #[\NoDiscard]
    public function asTenant(string $tenant): self
    {
        return clone($this, ['tenant' => $tenant]);
    }

    /**
     * A query called as from a page in panel $panel, rather than in its own.
     * A page request is in its route's, and an action in its own.
     */
    #[\NoDiscard]
    public function inPanel(string $panel): self
    {
        if ($panel === '') {
            throw new \InvalidArgumentException('A panel name cannot be empty.');
        }

        return clone($this, ['panel' => $panel]);
    }

    /** @param array<string, string> $headers */
    #[\NoDiscard]
    public function withHeaders(array $headers): self
    {
        return clone($this, ['headers' => [...$this->headers, ...array_change_key_case($headers)]]);
    }

    /** @param array<string, string> $cookies */
    #[\NoDiscard]
    public function withCookies(array $cookies): self
    {
        return clone($this, ['cookies' => [...$this->cookies, ...$cookies]]);
    }

    /**
     * Actions validated and never run: a Precognition call, of every field or
     * of the ones named.
     */
    #[\NoDiscard]
    public function precognitive(string ...$fields): self
    {
        return clone($this, ['precognition' => array_values($fields)]);
    }

    /**
     * render() without its model-leak check (ModelLeaks), for the test of a
     * page that shows a column on purpose -- named in the test, where a
     * reviewer sees it.
     */
    #[\NoDiscard]
    public function withoutModelLeakCheck(): self
    {
        return clone($this, ['leakCheck' => false]);
    }

    // ------------------------------------------------------------- pages

    /** A document request for $url, answered without the renderer. */
    public function get(string $url): PageResponse
    {
        return $this->page($url, []);
    }

    /** A client navigation (`RSC: 1`) to $url, answered without the renderer. */
    public function navigate(string $url): PageResponse
    {
        return $this->page($url, ['rsc' => '1', 'accept' => 'text/x-component']);
    }

    /**
     * A GET for $url with $headers, answered without the renderer. A client
     * navigation (`RSC: 1`) sends the build in `X-Version`, as the router
     * does, unless the test sends one of its own.
     *
     * @param array<string, string> $headers
     */
    public function page(string $url, array $headers): PageResponse
    {
        $kernel = $this->app->kernel();
        $build = $kernel->build();
        if (($headers['rsc'] ?? $this->headers['rsc'] ?? null) === '1' && $build !== null) {
            $headers = [...['x-version' => $build], ...$this->headers, ...$headers];
        }
        $request = $this->request('GET', $url, $headers);
        $encoder = new Encoder($kernel->report(...));
        $recorded = [];

        $respond = static function (LoadedRoute $loaded, Request $passed) use ($kernel, $encoder, &$recorded): Response {
            // Encoded here, inside the request: read-only, as the
            // application's renderer encodes it, and before the reset takes
            // the tenant and scope a Lazy field resolves under. The data
            // boundary is part of what a page test covers -- a model in a
            // loader's Data fails here as it fails in production.
            $encoded = $kernel->orm->readOnly(static fn (): array => $loaded->encode($encoder));
            $recorded[] = new RecordedRender($loaded, $passed, $encoded, $kernel->orm->connection()->statementCount());

            return Response::make(200, '', ['content-type' => $passed->wantsRscPayload() ? 'text/x-component' : 'text/html; charset=utf-8']);
        };

        $loads = $kernel->orm->lazyLoads();
        $from = $loads?->count() ?? 0;

        $response = $this->app->handleWith($request, $respond);

        return new PageResponse($this, $url, $request, $response, $recorded, $response->status >= 500 ? $this->app->lastFailure() : null, $loads?->since($from));
    }

    /**
     * A form posted to action $action the way a browser posts one before its
     * script loads: urlencoded, asking for HTML, through the whole request
     * path, with the session's CSRF token in the hidden field -- the one the
     * cookie a page set carries, so it follows a page's then(). Answered as
     * a browser is: a 303 on, or back to $from with the errors flashed.
     *
     * @param array{0: class-string, 1: string}|string $action
     * @param array<string, string> $fields
     */
    public function submit(array|string $action, array $fields, string $from = '/'): PageResponse
    {
        $name = self::actionName($this->app->kernel(), $action);
        $token = $this->cookies[VerifyCsrfToken::COOKIE] ?? throw new \LogicException(
            'A form is posted with the CSRF token its page was given: get() the page first, and submit() from its then().',
        );
        $fields = [...$fields, '_token' => $token];

        $request = new Request(
            'POST',
            ActionEndpoint::PREFIX . $name,
            [...$this->headers, 'content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html', 'referer' => "https://localhost{$from}"],
            [],
            $fields,
            $this->cookies,
            null,
            '127.0.0.1',
        );
        $loads = $this->app->kernel()->orm->lazyLoads();
        $from = $loads?->count() ?? 0;
        $response = $this->app->handleWith(
            $request,
            static fn (LoadedRoute $loaded, Request $passed): Response => Response::make(200),
            http_build_query($fields),
        );

        return new PageResponse($this, ActionEndpoint::PREFIX . $name, $request, $response, [], $response->status >= 500 ? $this->app->lastFailure() : null, $loads?->since($from));
    }

    /**
     * $url through the application's real renderer, from the pool; fails
     * when the render does, rather than reading its error page, and when
     * the payload carries a model column no Data object declares.
     */
    public function render(string $url): RenderedPage
    {
        $renderer = $this->renderer ?? throw new \LogicException(
            'render() needs a renderer pool: pass one to Testing::use(), and build the application on its socket.',
        );
        $renderer->start();

        $request = $this->request('GET', $url, []);
        $kernel = $this->app->kernel();
        $result = null;
        $body = '';
        $snapshot = null;
        $statements = 0;
        $loads = $kernel->orm->lazyLoads();
        $from = $loads?->count() ?? 0;

        $rendered = static function (RenderResult $measured, string $bytes) use ($kernel, &$result, &$body, &$snapshot, &$statements): void {
            $result = $measured;
            $body = $bytes;
            // What the request read, while the Orm still holds it: the
            // reset clears it the moment handle() returns.
            $snapshot = ModelLeaks::snapshot($kernel->orm);
            $statements = $kernel->orm->connection()->statementCount();
        };

        // Every read recorded, for the leak check: the identity map misses
        // those across tenants or a subtree (ModelLeaks).
        $kernel->orm->recordReads(true);
        try {
            $response = $this->app->handleWith(
                $request,
                fn (LoadedRoute $loaded, Request $passed): Response => $this->app->respond($loaded, $passed, $rendered),
            );
        } finally {
            $kernel->orm->recordReads(false);
        }

        // "Renderer errors fail the test instead of falling back to an
        // error page": the cause itself, where the application kept it.
        if ($response->status >= 500 || $result === null) {
            $failure = $this->app->lastFailure();
            if ($failure instanceof RendererException) {
                throw $failure;
            }

            throw new \RuntimeException(
                "Rendering {$url} failed with {$response->status}"
                . ($failure === null ? '.' : ': ' . $failure::class . ': ' . $failure->getMessage()),
                previous: $failure,
            );
        }

        $page = new RenderedPage($url, $response, $result, $body, $statements, $snapshot ?? [], $loads?->since($from));

        // "assertNoModelLeak() runs on every rendered page test by default."
        return $this->leakCheck ? $page->assertNoModelLeak() : $page;
    }

    // ------------------------------------------------------- direct calls

    /**
     * Calls query $query -- its class or its name -- in PHP: its arguments
     * resolved, its #[Authorize] asked, its body run read-only, its value
     * encoded, as a render's call is. A model argument is sent as its id,
     * and read again scoped to the tenant, as the renderer's would be.
     *
     * Run without the query cache, so the body is what answers: a test of
     * caching reads the Kernel's cache itself.
     */
    public function query(string $query, mixed ...$arguments): QueryResult
    {
        $kernel = $this->app->kernel();
        $name = self::queryName($kernel, $query);
        $args = self::input($arguments);

        $data = null;
        $runner = new QueryRunner(
            registry: $kernel->queries,
            orm: $kernel->orm,
            gate: $kernel->gate,
            encoder: new Encoder($kernel->report(...)),
            // Messages kept: a test is told why its call was refused.
            debug: true,
            report: $kernel->report(...),
            panels: $kernel->queryPanelRefusal(...),
            returned: static function (string $called, array $with, mixed $value) use (&$data): void {
                $data = $value;
            },
            // A query faked in this test (Query::fake()) answers here as it
            // answers a render: after every check, before the encoder.
            standIn: $kernel->standIns === null ? null : $kernel->standIns->query(...),
        );

        // The query's own panel -- by its #[Panel] or its namespace, as an
        // HTTP call to it would run -- unless the test names one: the panel
        // of the page it is called from, which a render runs its queries
        // behind. Either way the runner holds the call to its membership.
        $class = $kernel->queries->classFor($name);
        $panel = $this->panel ?? ($class === null ? null : $kernel->entry->panelFor($kernel->queries->panelsFor($name), $class)?->name);

        try {
            $results = $this->within(
                static fn (): array => $runner->run([['id' => 1, 'name' => $name, 'args' => $args]], [$name]),
                $panel,
            );
        } finally {
            // As at the end of every request: the identity map, the batch
            // scheduler the runner began and the rest are not the next call's.
            $kernel->scope->reset();
        }

        $result = $results[0];

        return new QueryResult($name, $result['value'] ?? null, $data, $result['error'] ?? null);
    }

    /**
     * Calls action $action -- [Class::class, 'method'] or its name -- in PHP,
     * through its endpoint without the transport: its tenant, its panel's
     * stack, its own #[Middleware], model resolution, #[Authorize], the form
     * request and the body, with its input sent as JSON, as a client sends
     * it. A model argument is sent as its id.
     *
     * @param array{0: class-string, 1: string}|string $action
     */
    public function action(array|string $action, mixed ...$arguments): ActionResponse
    {
        $kernel = $this->app->kernel();
        $name = self::actionName($kernel, $action);
        $body = json_encode(self::input($arguments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'];
        if ($this->precognition !== null) {
            $headers['precognition'] = 'true';
            if ($this->precognition !== []) {
                $headers['precognition-validate-only'] = implode(',', $this->precognition);
            }
        }
        $request = $this->request('POST', ActionEndpoint::PREFIX . $name, $headers);

        $failure = null;
        // Logging nowhere: the exception is kept for the test's message.
        $exceptions = new ExceptionHandler(static function (): void {});

        try {
            $response = $this->within(function () use ($kernel, $name, $request, $body): Response {
                // "Actions and queries require an authenticated user" unless
                // they say #[PublicAccess] -- the one step of the endpoint
                // stack that is not transport.
                $compiled = $kernel->actions->has($name) ? $kernel->actions->get($name) : null;
                if ($compiled !== null && !PublicAccess::declaredOn($compiled->class, $compiled->method) && !$kernel->auth->check()) {
                    throw new \Tinvar\Http\HttpException(401, 'Authentication required.', 'unauthenticated');
                }

                return $kernel->actions_->handle($request, $body);
            }, null);
        } catch (\Throwable $e) {
            $failure = $e;
            $response = $exceptions->handle($e, $request);
        } finally {
            // As at the end of every request: what the call left -- the
            // identity map, a panel, a transaction -- is not the next one's.
            $kernel->scope->reset();
        }

        return new ActionResponse($name, $response, $failure);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Runs $work as this client's user, in its tenant and in $panel.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function within(\Closure $work, ?string $panel): mixed
    {
        $kernel = $this->app->kernel();
        $inner = $work;

        if ($panel !== null) {
            $panels = $kernel->panels ?? throw new \LogicException("The call is in panel '{$panel}', and the application defines no panels.");
            $inner = static fn (): mixed => $panels->within($panel, $work);
        }
        if ($this->tenant !== null) {
            $tenant = $this->tenant;
            $scoped = $inner;
            $inner = static fn (): mixed => $kernel->orm->actAs($tenant, $scoped);
        }

        /** @var T */
        return $kernel->auth->actingAs($this->user, $inner, $this->epoch);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $url, array $headers): Request
    {
        if ($method === 'GET' && ($this->tenant !== null || $this->panel !== null)) {
            throw new \LogicException(
                'A page request is in the tenant and panel its route declares; asTenant() and inPanel() are for direct calls. '
                . 'Send the host a tenant is resolved from with withHeaders().',
            );
        }

        $parts = explode('?', $url, 2);
        $path = $parts[0];
        $query = $parts[1] ?? null;
        parse_str($query ?? '', $parsed);
        /** @var array<string, mixed> $parsed */

        $build = fn (array $cookies): Request => new Request(
            $method,
            $path === '' ? '/' : $path,
            [...['accept' => 'text/html'], ...$this->headers, ...$headers],
            $method === 'GET' ? $parsed : [],
            [],
            $cookies,
            $query,
            '127.0.0.1',
        );

        $request = $build($this->cookies);
        if ($this->user === null || $method !== 'GET') {
            return $request;
        }

        return $build([...$this->cookies, ...$this->app->signedIn($this->user, $request)]);
    }

    /**
     * Arguments as a client sends them: a model as its id, an enum as its
     * value, everything else as it is -- and named, never positional.
     *
     * @param array<array-key, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function input(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $name => $value) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('Pass a query or action its arguments by name: query(Stats::class, rack: $rack).');
            }
            $out[$name] = self::plain($value);
        }

        return $out;
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Model => $value->primaryKey(),
            $value instanceof \BackedEnum => $value->value,
            \is_array($value) => array_map(self::plain(...), $value),
            default => $value,
        };
    }

    /** The name query $query -- its class or its name -- is registered under. */
    public static function queryName(Kernel $kernel, string $query): string
    {
        if ($kernel->queries->has($query)) {
            return $query;
        }
        foreach ($kernel->queries->names() as $name) {
            if ($kernel->queries->classFor($name) === $query) {
                return $name;
            }
        }

        throw new \InvalidArgumentException("No query {$query} is registered with the application.");
    }

    /**
     * The name action $action -- [Class::class, 'method'] or its name -- is
     * registered under.
     *
     * @param array{0: class-string, 1: string}|string $action
     */
    public static function actionName(Kernel $kernel, array|string $action): string
    {
        if (\is_string($action)) {
            return $kernel->actions->has($action) ? $action : throw new \InvalidArgumentException("No action named '{$action}' is registered with the application.");
        }

        foreach ($kernel->actions->all() as $compiled) {
            if ($compiled->class === $action[0] && $compiled->method === $action[1]) {
                return $compiled->name;
            }
        }

        throw new \InvalidArgumentException("{$action[0]}::{$action[1]}() is not a registered action.");
    }
}
