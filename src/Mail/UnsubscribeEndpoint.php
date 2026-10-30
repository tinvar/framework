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
 * unsubscribe its owner from everything.
 *
 * Where it lands is the application's to name (ADR 0116): given a
 * $landing path, a GET of a link that verifies is sent there -- a 303 on
 * this origin, the token in its query, no Referer, kept by nothing -- and
 * the page there shows the reader what the link would do and asks before
 * it does it, as its own action under CSRF. A GET still writes nothing,
 * and one of a link that does not verify is the same 404 as a POST's.
 * Without a landing page, a GET is refused as before.
 */
final readonly class UnsubscribeEndpoint
{
    /** RFC 8058's body is one field; a provider's multipart form a few hundred bytes. */
    public const int MAX_BODY_BYTES = 4096;

    /** Longer than any token UnsubscribeLinks makes. */
    private const string TOKEN_SHAPE = '/^v1\.[A-Za-z0-9_-]{1,1200}\.[A-Za-z0-9_-]{1,100}\z/';

    /** The query parameter the landing page is handed the token in. */
    public const string PARAMETER = 'token';

    /**
     * @param string|null $landing the root-relative path of the application's page a
     *        reader who opens the link lands on; none, and a GET is refused
     */
    public function __construct(
        private UnsubscribeLinks $links,
        private Unsubscriber $unsubscriber,
        private Orm $orm,
        private ?string $landing = null,
    ) {
        // Root-relative, never `//host`, and no query or fragment of its own:
        // the token is the one thing added, and it goes nowhere but here.
        if ($landing !== null && preg_match('#^/(?![/\\\\])[A-Za-z0-9/_.~-]*\z#', $landing) !== 1) {
            throw new \InvalidArgumentException("'{$landing}' is not a page a reader lands on: a root-relative path, no query.");
        }
    }

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, UnsubscribeLinks::PATH . '/');
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        if (($request->method === 'GET' || $request->method === 'HEAD') && $this->landing !== null) {
            return $this->land($request);
        }
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'A one-click unsubscribe is POSTed to.', 'method_not_allowed', ['allow' => 'POST']);
        }
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'The unsubscribe body is over the limit.', 'payload_too_large');
        }
        if (!self::isOneClick($request, $rawBody)) {
            throw new HttpException(400, 'A one-click unsubscribe POSTs List-Unsubscribe=One-Click.', 'bad_request');
        }

        [, $claims] = $this->verified($request);

        $this->orm->actAs($claims['tenant'], fn () => $this->unsubscriber->unsubscribe($claims['list'], $claims['recipient']));

        // RFC 8058: answered, not redirected -- a server POSTed it, and a
        // redirect would only send it somewhere else.
        return Response::make(200, "Unsubscribed.\n", [
            'content-type' => 'text/plain; charset=utf-8',
            'cache-control' => 'no-store',
        ]);
    }

    /**
     * A GET of the link, sent on to the application's page: nothing is
     * written, nothing run in the link's tenant, and a link that does not
     * verify lands nowhere.
     */
    private function land(Request $request): Response
    {
        [$token] = $this->verified($request);

        return Response::make(303, '', [
            'location' => $this->landing . '?' . self::PARAMETER . '=' . $token,
            'cache-control' => 'no-store',
            'referrer-policy' => 'no-referrer',
        ]);
    }

    /**
     * The token in the path and what it names, or the 404 every link that
     * does not verify gets.
     *
     * @return array{string, array{list: string, recipient: string, tenant: string}}
     */
    private function verified(Request $request): array
    {
        $token = substr($request->path, \strlen(UnsubscribeLinks::PATH) + 1);
        $claims = preg_match(self::TOKEN_SHAPE, $token) === 1 ? $this->links->verify($token) : null;
        if ($claims === null) {
            throw new HttpException(404, 'No unsubscribe link answers here.', 'not_found');
        }

        return [$token, $claims];
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
