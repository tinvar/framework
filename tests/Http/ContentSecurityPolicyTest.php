<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\ContentSecurityPolicy;

use Tinvar\Http\ExceptionHandler;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;

/**
 * tinvar-spec.md, "Renderer hardening": "PHP sets a strict CSP with a
 * per-request nonce and `strict-dynamic`, and passes the nonce in the
 * `render` frame. Script injected into the HTML stream doesn't run."
 *
 * Nothing sent one. The rendered document is covered end to end in
 * RenderPathTest and in the browser; these are the policies themselves.
 */
it('is the policy the spec describes, for the nonce given', function () {
    expect(\Shop\Kernel::contentSecurityPolicy('abc+/=='))
        ->toBe("script-src 'nonce-abc+/==' 'strict-dynamic'; object-src 'none'; base-uri 'none'");
});

// React's development build rebuilds server components' stacks in the
// browser with eval; dev mode allows it, and production never does.
it('allows eval in dev, and only there', function () {
    expect(\Shop\Kernel::contentSecurityPolicy('abc', dev: true))
        ->toBe("script-src 'nonce-abc' 'strict-dynamic' 'unsafe-eval'; object-src 'none'; base-uri 'none'")
        ->and(\Shop\Kernel::contentSecurityPolicy('abc'))->not->toContain('unsafe-eval');
});

// The framework's own HTML response runs no script, so it allows none.
it('allows no script on the framework error page', function () {
    $response = new ExceptionHandler()->handle(
        new HttpException(404, 'No such rack.', 'not_found'),
        new Request('GET', '/racks/nope', ['accept' => 'text/html']),
    );

    expect($response->header('content-type'))->toStartWith('text/html')
        ->and($response->header('content-security-policy'))->toBe("script-src 'none'; object-src 'none'; base-uri 'none'");
});
