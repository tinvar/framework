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
    expect(\Racks\Kernel::contentSecurityPolicy('abc+/=='))
        ->toBe("script-src 'nonce-abc+/==' 'strict-dynamic'; object-src 'none'; base-uri 'none'");
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
