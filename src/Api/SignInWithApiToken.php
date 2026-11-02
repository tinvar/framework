<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * Signs a request's user in from the API token it presents, and refuses one
 * that presents none, or one that does not hold (ADR 0159).
 *
 * Run where a session's middleware never is: in front of `/_api` and of
 * each endpoint marked #[RequiresScope], whose requests reach it with no
 * cookie, so the token is the only credential there is. The token travels
 * on as a request attribute (ApiTokens::REQUEST_ATTRIBUTE) for what holds
 * it to its scopes, tenant and panel after the entry checks.
 *
 * A refusal is a 401 with `WWW-Authenticate: Bearer` (RFC 6750 3), saying
 * `invalid_token` for a token that was presented and does not hold -- and
 * never which part of it failed.
 */
final readonly class SignInWithApiToken implements Middleware
{
    /** What a refusal names the realm: the application's API. */
    public const string REALM = 'api';

    public function __construct(
        private ApiTokens $tokens,
        private AuthContext $auth,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $bearer = ApiTokenSecret::bearerOf($request);
        if ($bearer === null) {
            throw new HttpException(401, 'An API call presented no token.', 'api_token_required', [
                'www-authenticate' => 'Bearer realm="' . self::REALM . '"',
            ]);
        }

        $presented = $this->tokens->authenticate($bearer);
        if ($presented === null) {
            throw new HttpException(401, 'An API call presented a token that does not hold.', 'api_token_invalid', [
                'www-authenticate' => 'Bearer realm="' . self::REALM . '", error="invalid_token"',
            ]);
        }

        $this->auth->login($presented->user, $presented->epoch, $presented->token->id);

        return $next($request->withAttribute(ApiTokens::REQUEST_ATTRIBUTE, $presented));
    }

    /** The token that signed a request's user in; null where none did. */
    public static function presentedBy(Request $request): ?PresentedApiToken
    {
        $presented = $request->attribute(ApiTokens::REQUEST_ATTRIBUTE);

        return $presented instanceof PresentedApiToken ? $presented : null;
    }

    /**
     * The refusal of a token that lacks a scope (RFC 6750 3.1): a 403 that
     * names every scope the call needs, so its holder knows what to ask for.
     *
     * @param list<string> $scopes
     */
    public static function insufficientScope(array $scopes): HttpException
    {
        return new HttpException(403, 'An API token lacks a scope the call requires.', 'insufficient_scope', [
            'www-authenticate' => 'Bearer realm="' . self::REALM . '", error="insufficient_scope", scope="' . implode(' ', $scopes) . '"',
        ]);
    }
}
