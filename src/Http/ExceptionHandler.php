<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Orm\NoTenantContext;
use Tinvar\Support\Digest;
use Tinvar\Validation\ValidationException;

/**
 * Turns a thrown outcome into a response, and keeps the message out of it.
 *
 * The taxonomy was already sound -- HttpException is the client-facing family,
 * and RoutingException, ActionException and AuthorizationException mean "a
 * declaration bug, 500" -- but nothing implemented it. Router::handle() did not
 * catch, the endpoints caught only ValidationException, and there was no
 * handler anywhere, so every refusal the framework raised escaped uncaught.
 *
 * That mattered beyond tidiness, because the messages were written on the
 * assumption that something classified them:
 *
 *   NotFoundException::model()  "No App\Models\Rack with the given id ..."
 *   ForbiddenException::check() "'update' denied on App\Actions\Racks::move()."
 *   ActionException::badReturn() names the class, method and returned type.
 *
 * HttpException's own docblock promises "nothing here reaches the browser
 * unless an error page chooses to show it". This is the error page, and it
 * chooses not to: the body carries a stable machine-readable code and a fixed
 * reason phrase, never getMessage(). The message goes to the log, with a
 * digest the client is given so a report can be matched to a log line without
 * the client learning anything about the failure.
 */
final class ExceptionHandler
{
    /** @param (\Closure(\Throwable, string): void)|null $log receives the throwable and its digest */
    public function __construct(private readonly ?\Closure $log = null) {}

    public function handle(\Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            // "With JavaScript, a failed action returns errors with the
            // payload." The field errors ARE the answer here, and they are
            // the framework's own text about the client's own input.
            return $this->respond(422, ['errors' => $e->errors], $request);
        }

        // Classified as normalize() says; logged and digested as itself, so
        // the line names what failed and where, and the digest finds it.
        $original = $e;
        $e = self::normalize($e);

        if ($e instanceof HttpException) {
            $this->log($original, $digest = self::digest($original));

            $response = $this->respond($e->status, [
                'error' => [
                    'code' => $e->code_ !== '' ? $e->code_ : self::codeFor($e->status),
                    'message' => HttpError::reason($e->status),
                    'digest' => $digest,
                ],
            ], $request);

            // Some refusals have to say more than "no": a 429 without
            // Retry-After tells a client to back off without saying how far.
            foreach ($e->headers as $header => $value) {
                $response = $response->withHeader($header, $value);
            }

            return $response;
        }

        // Everything else is a bug, not a client error. The class, the
        // message and the trace stay on this side of the wire entirely.
        $this->log($e, $digest = self::digest($e));

        return $this->respond(500, [
            'error' => [
                'code' => 'internal_error',
                'message' => HttpError::reason(500),
                'digest' => $digest,
            ],
        ], $request);
    }

    /**
     * What handle() answers $e with, as an #[ErrorPage]'s loader is told it:
     * the status, the code, the fixed reason and the digest -- the same
     * digest the log line is under, so the page's reference finds it.
     */
    public function errorFor(\Throwable $e): HttpError
    {
        if ($e instanceof ValidationException) {
            return new HttpError(422, 'unprocessable', self::reason(422), self::digest($e));
        }

        $classified = self::normalize($e);
        $status = $classified instanceof HttpException ? $classified->status : 500;
        $code = $classified instanceof HttpException && $classified->code_ !== '' ? $classified->code_ : self::codeFor($status);

        return new HttpError($status, $code, self::reason($status), self::digest($e), $classified instanceof HttpException ? $classified->headers : []);
    }

    /**
     * Tenancy failing closed: a #[TenantScoped] read with no tenant in
     * force. Classified rather than left a bare 500, because it is not
     * always a bug -- a page reached on a host, or by a session, that names
     * no tenant is a request for something that is not there -- and it says
     * nothing more than that. What it is answered as: the failure itself,
     * naming the model, goes to the log under its own digest like any other.
     */
    private static function normalize(\Throwable $e): \Throwable
    {
        return match (true) {
            $e instanceof NoTenantContext => new HttpException(404, $e->getMessage(), 'no_tenant'),
            // A refusal from above the core with a status of its own -- an API
            // token refused (ADR 0159), a 403 for a caller who may not have
            // one -- is the user's to hear of, not a bug: answered with its
            // status rather than a 500.
            $e instanceof HasHttpStatus => new HttpException($e->httpStatus(), $e->getMessage(), $e->httpCode()),
            default => $e,
        };
    }

    /**
     * Logs a failure that is not this request's outcome, and says what it
     * was logged under.
     *
     * A rescued field or fragment fails without failing the response, so
     * nothing reaches handle(); this is how it still reaches the log. The
     * digest is the one the client is given -- in the Rescued marker, or on
     * the renderer's error row -- so a reference quoted to support finds the
     * line. A caller that has already shown one passes it.
     */
    public function report(\Throwable $e, ?string $digest = null): string
    {
        $digest ??= self::digest($e);
        $this->log($e, $digest);

        return $digest;
    }

    /** See Digest::of(). */
    public static function digest(\Throwable $e): string
    {
        return Digest::of($e);
    }

    /** The fixed reason phrase for a status: never an exception's message (HttpError). */
    public static function reason(int $status): string
    {
        return HttpError::reason($status);
    }

    /** The machine-readable code the framework gives a status when nothing more specific was thrown (HttpError). */
    public static function codeFor(int $status): string
    {
        return HttpError::codeFor($status);
    }

    private function log(\Throwable $e, string $digest): void
    {
        if ($this->log !== null) {
            ($this->log)($e, $digest);
        }
    }

    /**
     * JSON for the endpoints, HTML for a browser asking for a page.
     *
     * The handler took a Request and ignored it, so a browser hitting a 404
     * through the router branch got a JSON body. Negotiation is on Accept,
     * and JSON is the default: the two endpoints are the JSON callers, and a
     * client that states no preference is more likely to be one of them than
     * a person with a browser.
     *
     * The HTML carries the same three things the JSON does -- status, code,
     * digest -- and no more. An error page that says more than the API would
     * is how the message rules get lost.
     *
     * @param array<string, mixed> $payload
     */
    private function respond(int $status, array $payload, Request $request): Response
    {
        if (!self::wantsHtml($request)) {
            return Response::make(
                $status,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ['content-type' => 'application/json', 'cache-control' => 'private, no-store'],
            );
        }

        $error = $payload['error'] ?? [];
        $code = \is_array($error) ? (string) ($error['code'] ?? '') : '';
        $digest = \is_array($error) ? (string) ($error['digest'] ?? '') : '';
        $reason = HttpError::reason($status);

        $html = sprintf(
            '<!doctype html><meta charset="utf-8"><title>%d</title>'
            . '<h1>%d</h1><p>%s</p><p><small>%s %s</small></p>',
            $status,
            $status,
            htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($digest, ENT_QUOTES, 'UTF-8'),
        );

        // The one HTML response the framework builds itself, so its policy
        // is the framework's: the page runs no script at all, and says so.
        return Response::make($status, $html, [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'private, no-store',
            'content-security-policy' => "script-src 'none'; object-src 'none'; base-uri 'none'",
        ]);
    }

    private static function wantsHtml(Request $request): bool
    {
        $accept = $request->header('accept');

        if ($accept === null || str_contains($accept, 'application/json')) {
            return false;
        }

        return str_contains($accept, 'text/html');
    }
}
