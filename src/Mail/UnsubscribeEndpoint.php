<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;

/**
 * POST /_mail/unsubscribe/{token}
 *
 * RFC 8058's one-click unsubscribe: a mail client, or its provider's
 * server, POSTs `List-Unsubscribe=One-Click` to the https URL in an email's
 * List-Unsubscribe header, and its reader is unsubscribed -- no page, no
 * confirmation, no redirect (ADR 0048).
 *
 * No session, cookie, CSRF or global middleware, as for a webhook: the
 * request comes from a server, not the reader's browser, and the token in
 * the path is what a session is to a page. It is checked before anything
 * runs, and every way it can fail -- forged, expired, of another purpose --
 * is the same 404. Then the application's Unsubscriber runs inside the
 * tenant the link was made in, as nobody.
 *
 * POST only, with the body RFC 8058 says. A GET is a link scanner, or a
 * reader whose client opened the URL as a page: neither unsubscribes
 * anyone, since a scanner following every link in a mailbox would
 * unsubscribe its owner from everything. The page a reader lands on from
 * the email's own unsubscribe link is the application's.
 */
final readonly class UnsubscribeEndpoint
{
    /** RFC 8058's body is one field; a provider's multipart form a few hundred bytes. */
    public const int MAX_BODY_BYTES = 4096;

    /** Longer than any token UnsubscribeLinks makes. */
    private const string TOKEN_SHAPE = '/^v1\.[A-Za-z0-9_-]{1,1200}\.[A-Za-z0-9_-]{1,100}\z/';

    public function __construct(
        private UnsubscribeLinks $links,
        private Unsubscriber $unsubscriber,
        private Orm $orm,
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, UnsubscribeLinks::PATH . '/');
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'A one-click unsubscribe is POSTed to.', 'method_not_allowed', ['allow' => 'POST']);
        }
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'The unsubscribe body is over the limit.', 'payload_too_large');
        }
        if (!self::isOneClick($request, $rawBody)) {
            throw new HttpException(400, 'A one-click unsubscribe POSTs List-Unsubscribe=One-Click.', 'bad_request');
        }

        $token = substr($request->path, \strlen(UnsubscribeLinks::PATH) + 1);
        $claims = preg_match(self::TOKEN_SHAPE, $token) === 1 ? $this->links->verify($token) : null;
        if ($claims === null) {
            throw new HttpException(404, 'No unsubscribe link answers here.', 'not_found');
        }

        $this->orm->actAs($claims['tenant'], fn () => $this->unsubscriber->unsubscribe($claims['list'], $claims['recipient']));

        // RFC 8058: answered, not redirected -- a server POSTed it, and a
        // redirect would only send it somewhere else.
        return Response::make(200, "Unsubscribed.\n", [
            'content-type' => 'text/plain; charset=utf-8',
            'cache-control' => 'no-store',
        ]);
    }

    /**
     * Whether the POST says what RFC 8058 has it say, as a urlencoded or a
     * multipart form -- PHP parsed either into the body -- or a raw
     * urlencoded one the adapter left unparsed.
     */
    private static function isOneClick(Request $request, string $rawBody): bool
    {
        if (($request->body['List-Unsubscribe'] ?? null) === 'One-Click') {
            return true;
        }

        parse_str($rawBody, $fields);

        return ($fields['List-Unsubscribe'] ?? null) === 'One-Click';
    }
}
