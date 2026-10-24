<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\Application;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\RenderResult;
use Tinvar\Routing\LoadedRoute;

/**
 * What an application shows the testing layer, beside what a worker asks of
 * it.
 *
 * The framework's Kernel already has the seam a page test needs: handle()
 * takes the closure that turns a loaded route into a response, and that
 * closure is where PHP's work ends and the renderer's begins. An application
 * wraps handle() in its own concerns -- a session cookie issued before
 * anything reads one -- so it is the application that hands the closure
 * through, rather than a test calling the Kernel past them.
 *
 * Signing someone in is the application's too: the framework has no session
 * layer (the README says so), so it cannot make a request arrive as a user.
 * signedIn() is PHP, never a route, and only code already inside the process
 * -- holding the app key and the database -- can call it.
 */
interface TestableApplication extends Application
{
    /** The framework's composition root every request goes through. */
    public function kernel(): Kernel;

    /**
     * One request, exactly as handle() serves it, with $respond rendering a
     * matched route -- and an error page -- in place of the application's
     * renderer.
     *
     * @param \Closure(LoadedRoute, Request): Response $respond
     */
    public function handleWith(Request $request, \Closure $respond, string $rawBody = ''): Response;

    /**
     * How the application renders a matched route: the closure its own
     * handle() passes. $rendered, when given, is told what the render
     * measured and the bytes it wrote, before the request's state is reset.
     *
     * @param (\Closure(RenderResult, string): void)|null $rendered
     */
    public function respond(LoadedRoute $loaded, Request $request, ?\Closure $rendered = null): Response;

    /**
     * The cookies a browser signed in as $user sends with $request: a session
     * made for them as signing in would make it, with no password asked --
     * for the host $request goes to, where an application binds a session
     * to one.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException for a user the application cannot sign in
     */
    public function signedIn(Authenticatable $user, Request $request): array;

    /**
     * The last failure the Kernel classified while serving a request, or null:
     * the cause behind a 500 a test reads as a status and a digest.
     */
    public function lastFailure(): ?\Throwable;
}
