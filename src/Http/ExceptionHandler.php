<?php

declare(strict_types=1);

namespace Tinvar\Http;

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
    /** Fixed reason phrases. Keyed by status, never by exception message. */
    private const array REASONS = [
        400 => 'The request could not be understood.',
        401 => 'Authentication is required.',
        403 => 'You may not do that.',
        404 => 'Not found.',
        405 => 'That method is not allowed here.',
        413 => 'The request is too large.',
        419 => 'The session has expired.',
        422 => 'The request could not be processed.',
        500 => 'Something went wrong.',
    ];

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

        if ($e instanceof HttpException) {
            $this->log($e, $digest = self::digest($e));

            return $this->respond($e->status, [
                'error' => [
                    'code' => $e->code_ !== '' ? $e->code_ : self::codeFor($e->status),
                    'message' => self::REASONS[$e->status] ?? self::REASONS[500],
                    'digest' => $digest,
                ],
            ], $request);
        }

        // Everything else is a bug, not a client error. The class, the
        // message and the trace stay on this side of the wire entirely.
        $this->log($e, $digest = self::digest($e));

        return $this->respond(500, [
            'error' => [
                'code' => 'internal_error',
                'message' => self::REASONS[500],
                'digest' => $digest,
            ],
        ], $request);
    }

    /**
     * A stable, non-reversible handle on one failure.
     *
     * Enough to find the log line, not enough to learn anything: the class and
     * location go through a hash, and the message never does, since it may
     * quote the client's own input back.
     */
    public static function digest(\Throwable $e): string
    {
        return substr(hash('sha256', $e::class . '|' . $e->getFile() . '|' . $e->getLine()), 0, 16);
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            413 => 'body_too_large',
            419 => 'csrf_token_mismatch',
            422 => 'unprocessable',
            default => 'internal_error',
        };
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
        $reason = self::REASONS[$status] ?? self::REASONS[500];

        $html = sprintf(
            '<!doctype html><meta charset="utf-8"><title>%d</title>'
            . '<h1>%d</h1><p>%s</p><p><small>%s %s</small></p>',
            $status,
            $status,
            htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($digest, ENT_QUOTES, 'UTF-8'),
        );

        return Response::make(
            $status,
            $html,
            ['content-type' => 'text/html; charset=utf-8', 'cache-control' => 'private, no-store'],
        );
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
