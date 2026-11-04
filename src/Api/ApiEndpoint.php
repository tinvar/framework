<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Calls\CallResult;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpError;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Support\Str;

/**
 * `POST /_api/{operation}`: an action or a query marked #[RequiresScope],
 * called with an API token (ADR 0159).
 *
 *     curl -X POST https://shop.example/_api/orders.yours \
 *       -H 'Authorization: Bearer tvr_...' -H 'Content-Type: application/json' \
 *       -d '{"status": "shipped"}'
 *
 * The body is a JSON object of the operation's inputs, as a browser's call
 * sends them. The Kernel runs this behind the flood guard and the `api`
 * throttle by address, with no session and every cookie taken off: the
 * token is the only credential there is, so no CSRF token is asked for.
 * Here the token signs its user in, the `api` throttle is spent again by
 * their account, and ApiCalls holds and runs the call.
 *
 * What it answers:
 *
 *   - 200 with the operation's value as JSON -- an action's returned Data,
 *     or null for none; a query's value;
 *   - 401 for no token, or one that does not hold, with WWW-Authenticate;
 *   - 403 for a token lacking a scope (`insufficient_scope`, naming them),
 *     or presented outside its tenant or panel; or an #[Authorize] or
 *     membership that said no (`forbidden`);
 *   - 404 for an operation no #[RequiresScope] exposes, or not on this
 *     host, or a model id that names nothing in scope;
 *   - 422 with `{"errors": {field: [message]}}` for input that does not
 *     validate;
 *   - 429 with Retry-After once a throttle is spent.
 *
 * Every refusal is the framework's error shape, `{"error": {"code",
 * "message", "digest"}}`, which the OpenAPI document describes.
 */
final readonly class ApiEndpoint
{
    public const string PREFIX = '/_api/';

    /** The route the telemetry counts every call under, never the name in the path. */
    public const string ROUTE = '_api';

    /**
     * @param int $limit `throttle:api` a minute, for the account pass the Kernel's stack could not
     *        run before the token signed its user in; 0 for none
     */
    public function __construct(
        private ApiCalls $calls,
        private SignInWithApiToken $signIn,
        private ?Throttle $throttle = null,
        private int $limit = 0,
        private int $maxBodyBytes = Limits::MAX_BODY_BYTES,
        private int $maxJsonDepth = Limits::MAX_JSON_DEPTH,
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    /** A call is a POST: anything else is a 405 naming it, before anything runs. */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'An API call is a POST.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody): Response
    {
        $name = substr($request->path, \strlen(self::PREFIX));
        // A name the client chose: one that is no operation's name is
        // refused as one that exists nowhere, before anything is read.
        if (!Str::isDottedIdentifier($name)) {
            throw new HttpException(404, 'No API operation has this name.', 'unknown_operation');
        }

        /** @var list<Middleware> $stack */
        $stack = [
            $this->signIn,
            ...($this->throttle === null || $this->limit <= 0 ? [] : [new ThrottleRequests($this->throttle, 'api', $this->limit, byAddress: false)]),
        ];

        return new Pipeline($stack)->run($request, function (Request $passed) use ($name, $rawBody): Response {
            $presented = SignInWithApiToken::presentedBy($passed) ?? throw new \LogicException('An API call ran with no token signed in.');
            $arguments = Limits::input($passed, $rawBody, $this->maxBodyBytes, $this->maxJsonDepth);

            return $this->respond($this->calls->call($presented, $name, $arguments, $passed), $name);
        });
    }

    private function respond(CallResult $result, string $name): Response
    {
        return match ($result->status) {
            CallResult::OK => self::json(200, $result->value),
            CallResult::INVALID => self::json(422, ['errors' => $result->errors]),
            CallResult::FORBIDDEN => throw new HttpException(403, "An #[Authorize] check or a panel's membership refused the API call to {$name}.", 'forbidden'),
            CallResult::NOT_FOUND => throw new HttpException(404, "The API call to {$name} named a record that is not there.", 'not_found'),
            // The application's own 4xx -- a plan, a state, a limit -- with its
            // status and code, for the client to act on.
            CallResult::REJECTED => throw new HttpException($result->httpStatus ?? 400, "The application refused the API call to {$name}.", $result->reason ?? 'rejected'),
            CallResult::REFUSED => throw match ($result->reason) {
                'unknown_operation' => new HttpException(404, 'No API operation has this name.', 'unknown_operation'),
                // Not on this host: as RequireHost answers an entry point
                // reached on another.
                'host' => new HttpException(404, "{$name} is not answered on this host.", 'not_found'),
                'insufficient_scope' => SignInWithApiToken::insufficientScope($this->calls->operations()[$name][1] ?? []),
                'panel' => new HttpException(403, "An API token was presented for {$name}, in a panel it was not issued in.", 'api_token_panel'),
                default => new HttpException(403, "An API token was presented for {$name} in a tenant it was not issued in.", 'api_token_tenant'),
            },
            default => self::json(500, ['error' => [
                'code' => 'internal_error',
                'message' => HttpError::reason(500),
                'digest' => $result->digest ?? '',
            ]]),
        };
    }

    private static function json(int $status, mixed $body): Response
    {
        return Response::make(
            $status,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            [
                'content-type' => 'application/json',
                'cache-control' => 'private, no-store',
                'x-content-type-options' => 'nosniff',
                'cross-origin-resource-policy' => 'same-origin',
            ],
        );
    }
}
