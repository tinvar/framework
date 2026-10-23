<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\CsrfTokens;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * CSRF for every state-changing request.
 *
 * "Requests from JavaScript use the CSRF cookie and header." The cookie is
 * `__Host-` prefixed; the header mirrors it. A read-only method is exempt,
 * because it is not supposed to change anything -- and actions are always
 * POST, so there is no method spoofing to account for.
 *
 * With CsrfTokens -- which a Kernel given them always passes -- the token
 * must also be the request's own session's, so a pair that merely matches
 * itself is not enough. Without them it is a plain double submit, for an
 * application that issues its own cookie.
 *
 * A mismatch is 419, which the client turns into a same-origin redirect back
 * with a "page expired" flash rather than an error screen.
 */
final class VerifyCsrfToken implements Middleware
{
    public const string COOKIE = '__Host-XSRF-TOKEN';
    public const string HEADER = 'x-xsrf-token';

    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @param list<string> $except paths that carry their own verification */
    public function __construct(
        private readonly array $except = [],
        private readonly ?CsrfTokens $tokens = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (\in_array($request->method, self::SAFE_METHODS, true)
            || \in_array($request->path, $this->except, true)) {
            return $next($request);
        }

        $cookie = $request->cookie(self::COOKIE);
        $header = $request->header(self::HEADER) ?? self::formToken($request);

        // Empty is absent. An empty cookie and an empty header are equal,
        // and the comparison below took them as a matching pair: any client
        // that could send `Cookie: __Host-XSRF-TOKEN=` passed.
        //
        // hash_equals, not ===: a timing difference here leaks the token one
        // byte at a time.
        if ($cookie === null || $cookie === '' || $header === null || $header === ''
            || !hash_equals($cookie, $header)) {
            throw new HttpException(419, 'CSRF token mismatch.', 'page_expired');
        }

        // And the session's own token. A pair that matches itself is only as
        // good as the cookie's origin: one planted from outside this origin
        // comes with a header the planter also chose.
        if ($this->tokens !== null && !$this->tokens->verify($request, $header)) {
            throw new HttpException(419, 'CSRF token is not this session\'s.', 'page_expired');
        }

        return $next($request);
    }

    /**
     * The hidden field <Form> renders during dynamic server rendering, so a
     * form works before JavaScript loads.
     */
    private static function formToken(Request $request): ?string
    {
        $token = $request->body['_token'] ?? null;

        return \is_string($token) ? $token : null;
    }
}
