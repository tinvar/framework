<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\LazyLoad;
use Tinvar\Routing\LoadedRoute;

/**
 * A page request answered without the renderer: the response PHP gave, and
 * what it would have handed the renderer.
 *
 * "These run routing, middleware, layout authorize() and loaders, and page
 * loaders, then assert on the data that would go to React." Everything PHP
 * does for a page runs -- the session, CSRF cookie, panel stack, tenant,
 * every #[Authorize] in the chain, the loaders, the encoding -- and only the
 * render is left out, so most page tests need no Node at all.
 *
 * Also what the spec calls `Renderer::fake()`: recorded() is what PHP sent
 * to the renderer, without running it.
 */
final readonly class PageResponse
{
    /**
     * @param list<RecordedRender> $renders what the Kernel asked to have rendered, in
     *        order: the page, and an error page after it when the request failed
     * @param \Throwable|null $failure what the application reported for a 500
     * @param list<LazyLoad>|null $lazyLoads the lazy relation loads the request ran, or
     *        null when its Orm was not recording them
     */
    public function __construct(
        public Client $client,
        public string $url,
        public Request $request,
        public Response $response,
        public array $renders,
        public ?\Throwable $failure = null,
        public ?array $lazyLoads = null,
    ) {}

    public function status(): int
    {
        return $this->response->status;
    }

    public function header(string $name): ?string
    {
        return $this->response->header($name);
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->response->status, "GET {$this->url} was answered with {$this->response->status}, not {$status}." . $this->why());

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthenticated(): self
    {
        return $this->assertStatus(401);
    }

    /**
     * A redirect -- to $to, given one. A client navigation's redirect is the
     * router's 409 with X-Redirect or X-Location, and is read as one.
     */
    public function assertRedirect(?string $to = null): self
    {
        $status = $this->response->status;
        $location = $status === 409
            ? ($this->response->header('x-redirect') ?? $this->response->header('x-location'))
            : $this->response->header('location');

        Assert::assertTrue(
            $location !== null && ($status === 409 || ($status >= 300 && $status < 400)),
            "GET {$this->url} was answered with {$status}, not a redirect." . $this->why(),
        );
        if ($to !== null) {
            Assert::assertSame($to, $location, "GET {$this->url} redirected elsewhere.");
        }

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->response->header($name);
        Assert::assertNotNull($actual, "GET {$this->url} was answered without a {$name} header.");
        if ($value !== null) {
            Assert::assertSame($value, $actual, "GET {$this->url}'s {$name} header is not what was expected.");
        }

        return $this;
    }

    /**
     * The page $class rendered, with the data asserted inside $assert: what
     * its loader returned, as the renderer would have been handed it.
     *
     * @param class-string $class
     * @param (\Closure(AssertablePage): mixed)|null $assert
     */
    public function assertPage(string $class, ?\Closure $assert = null): self
    {
        $recorded = $this->recorded();
        Assert::assertSame($class, $recorded->loaded->chain->page->class, "GET {$this->url} rendered another page.");

        if ($assert !== null) {
            $assert(new AssertablePage($recorded->encoded['page'], $recorded->loaded->page, 'page', $this));
        }

        return $this;
    }

    /**
     * The request failed with $status, and was answered with the
     * application's #[ErrorPage] for it -- $class, given one.
     *
     * @param class-string|null $class
     */
    public function assertErrorPage(int $status, ?string $class = null): self
    {
        $this->assertStatus($status);
        $last = $this->renders === [] ? null : $this->renders[\count($this->renders) - 1];
        Assert::assertTrue($last?->isErrorPage() === true, "GET {$this->url} was answered with {$status} and no error page.");
        if ($class !== null) {
            Assert::assertSame($class, $last->loaded->chain->page->class, "GET {$this->url} was answered with another error page.");
        }

        return $this;
    }

    /**
     * The request issued at most $max statements before it would have been
     * rendered -- its middleware, checks and loaders: the CI guard for a
     * loader whose batching regressed.
     */
    public function assertQueryBudget(int $max): self
    {
        $statements = $this->recorded()->statements;
        Assert::assertLessThanOrEqual($max, $statements, "GET {$this->url} issued {$statements} statements, over its budget of {$max}.");

        return $this;
    }

    /**
     * No relation the request read lazily -- in its middleware, checks and
     * loaders, and the encoding of what they returned -- was loaded for one
     * parent alone: each load covered the result set its parent was read in
     * (LazyLoad). The CI guard for a loader that reads a relation in a loop
     * over models no batch can reach.
     */
    public function assertNoLazyLoadsOutsideBatch(): self
    {
        LazyLoadAssertion::assertNoneOutsideBatch($this->lazyLoads, "GET {$this->url}");

        return $this;
    }

    /**
     * The same browser, for its next request: the cookies this request sent,
     * with the ones its response set or cleared -- the session it was given
     * or rotated to, the CSRF token, a flash waiting. Acting as no one else:
     * the session says who it is.
     */
    public function then(): Client
    {
        $cookies = $this->request->cookies;
        foreach ($this->response->cookies() as $name => $line) {
            $value = explode(';', substr($line, \strlen($name) + 1), 2)[0];
            if (preg_match('/;\s*Max-Age=0(?:;|$)/i', $line) === 1) {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = $value;
            }
        }

        return new Client($this->client->app, $this->client->renderer)->withCookies($cookies);
    }

    /** The redirect this response is, followed by the same browser. */
    public function follow(): self
    {
        $this->assertRedirect();
        $location = $this->response->status === 409
            ? ($this->response->header('x-redirect') ?? $this->response->header('x-location'))
            : $this->response->header('location');

        return $this->then()->get((string) $location);
    }

    /** The page's render: what the renderer would have been handed for it. */
    public function recorded(): RecordedRender
    {
        foreach ($this->renders as $render) {
            if (!$render->isErrorPage()) {
                return $render;
            }
        }

        Assert::fail("GET {$this->url} rendered no page: it was answered with {$this->response->status}" . ($this->response->header('location') === null ? '' : ", to {$this->response->header('location')}") . '.' . $this->why());
    }

    public function loaded(): LoadedRoute
    {
        return $this->recorded()->loaded;
    }

    /** What the application reported for a failed request, if it did. */
    private function why(): string
    {
        if ($this->failure === null) {
            return '';
        }

        return "\nThe application reported " . $this->failure::class . ': ' . $this->failure->getMessage()
            . ' at ' . $this->failure->getFile() . ':' . $this->failure->getLine();
    }
}
