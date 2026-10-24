<?php

declare(strict_types=1);

namespace Tinvar\Protocol;

use Tinvar\Codec\Bin;

/**
 * The socket protocol's frame table (tinvar-spec.md, "Socket protocol").
 *
 * Direction is enforced, not merely documented. The security model requires
 * one-way frames: "The renderer sends output chunks, query calls, head tags and
 * done or error. It cannot set flash data or anything else that PHP persists."
 */
final class Frame
{
    // PHP -> renderer
    public const string RENDER = 'render';
    public const string FRAGMENT = 'fragment';
    public const string RESULT = 'result';
    public const string MINTED = 'minted';

    // renderer -> PHP
    public const string CHUNK = 'chunk';
    public const string QUERY = 'query';
    public const string META = 'meta';
    public const string MINT = 'mint';
    public const string DONE = 'done';
    public const string ERROR = 'error';

    /**
     * A subtree of a fragment failed and the render went on without it: the
     * payload carries an error row in its place, and this says so, so PHP
     * can log what the browser will only see a digest of. One-way like the
     * rest -- it is logged and nothing else -- and capped; see ADR 0017.
     */
    public const string REPORT = 'report';

    /** @var list<string> */
    public const array PHP_TO_RENDERER = [self::RENDER, self::FRAGMENT, self::RESULT, self::MINTED];

    /** @var list<string> */
    public const array RENDERER_TO_PHP = [
        self::CHUNK,
        self::QUERY,
        self::META,
        self::MINT,
        self::REPORT,
        self::DONE,
        self::ERROR,
    ];

    /**
     * What a digest from the renderer may look like. It goes into a log line
     * and back to the browser on an error row, and the renderer is the
     * semi-trusted side, so it is a short token and nothing else.
     */
    public const string DIGEST_SHAPE = '/^[A-Za-z0-9_-]{1,64}$/D';

    /**
     * How many fragment tokens one render may ask to have signed.
     *
     * The renderer is semi-trusted -- it "runs third-party npm code" -- and
     * minting is the one thing it can ask PHP to do with the signing key. The
     * cap bounds what a compromised renderer can extract: a page with more
     * than this many <Deferred> boundaries is a page that should be split.
     */
    public const int MAX_MINTS_PER_RENDER = 64;

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $loaderData  layout and page loader data, already encoded
     * @param array<array-key, string> $allowedQueries per-route query allowlist; sent as a
     *                                         list whatever its keys, so it crosses as an array
     * @param ?PageMetadata        $page for a document render; the renderer
     *                                   writes it into the HTML for the router
     * @param ?string              $csrfToken the session's CSRF token, for the
     *                                   hidden field <Form> renders so a form
     *                                   posts before JavaScript loads. Never in
     *                                   stored output: pass it only for a render
     *                                   made for one request.
     * @param array<string, mixed> $flash what a form posted without script left
     *                                   for this page: its errors, its input, a
     *                                   flash -- see FlashStore
     * @return array<string, mixed>
     */
    public static function render(
        string $routeId,
        array $params,
        array $loaderData,
        string $requestId,
        string $cspNonce,
        array $allowedQueries,
        ?PageMetadata $page = null,
        ?string $csrfToken = null,
        array $flash = [],
    ): array {
        $frame = [
            't' => self::RENDER,
            'route' => $routeId,
            'params' => $params,
            'data' => $loaderData,
            'rid' => $requestId,
            'nonce' => $cspNonce,
            'allow' => array_values($allowedQueries),
        ];

        if ($page !== null) {
            $frame['page'] = $page->toArray();
        }

        // Absent rather than empty: a render with nothing for its forms
        // sends what it always sent.
        if ($csrfToken !== null) {
            $frame['csrf'] = $csrfToken;
        }
        if ($flash !== []) {
            $frame['flash'] = $flash;
        }

        return $frame;
    }

    /**
     * @param array<string, mixed> $token already-verified fragment token contents
     * @param array<array-key, string> $allowedQueries sent as a list whatever its keys
     * @return array<string, mixed>
     */
    public static function fragment(array $token, string $requestId, string $cspNonce, array $allowedQueries): array
    {
        return [
            't' => self::FRAGMENT,
            'token' => $token,
            'rid' => $requestId,
            'nonce' => $cspNonce,
            'allow' => array_values($allowedQueries),
        ];
    }

    /**
     * A <Deferred group>'s subtrees in one render, whose payload's root is
     * the list of them in the order given. The same frame type as a lone
     * fragment -- the renderer treats both as payload-only and unable to
     * mint -- told apart by `tokens` in place of `token`.
     *
     * @param list<array<string, mixed>> $tokens already-verified fragment token contents
     * @param array<array-key, string> $allowedQueries sent as a list whatever its keys
     * @return array<string, mixed>
     */
    public static function fragments(array $tokens, string $requestId, string $cspNonce, array $allowedQueries): array
    {
        return [
            't' => self::FRAGMENT,
            'tokens' => $tokens,
            'rid' => $requestId,
            'nonce' => $cspNonce,
            'allow' => array_values($allowedQueries),
        ];
    }

    /**
     * One reply per call in the batch, in any order; `id` pairs them up.
     * Errors are classified codes in production, never exception messages.
     *
     * @param list<array{id: int, value?: mixed, error?: array{code: string, digest: string}}> $results
     * @return array<string, mixed>
     */
    public static function result(array $results): array
    {
        return ['t' => self::RESULT, 'r' => $results];
    }

    /** @return array<string, mixed> */
    public static function chunk(string $bytes): array
    {
        return ['t' => self::CHUNK, 'b' => new Bin($bytes)];
    }

    /**
     * Fragment tokens PHP has signed, answering a `mint` frame.
     *
     * Every request is answered. A null token is a refusal, and the renderer
     * fails that one boundary; a request with no answer at all would leave
     * the render waiting on it.
     *
     * @param array<int, string|null> $tokens request id => encoded token, or null for a refusal
     * @return array<string, mixed>
     */
    public static function minted(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $id => $token) {
            $out[] = ['id' => $id, 'token' => $token];
        }

        return ['t' => self::MINTED, 'm' => $out];
    }

    /**
     * Pulls the `{id, component, props}` requests out of a mint frame.
     *
     * The renderer asks for a token because it is the side that knows which
     * <Deferred> boundaries exist and what props they were given -- facts
     * that only exist while the tree renders. It supplies ONLY those two
     * fields. Route, params, panel, build, session and expiry are PHP's, and
     * are never taken from this frame: a compromised renderer must not be
     * able to mint a token for another route, another session or a longer
     * life than the framework issues.
     *
     * @param array<string, mixed> $frame
     * @return list<array{id: int, component: string, props: array<string, mixed>}>
     */
    public static function readMintBatch(array $frame): array
    {
        $requests = $frame['m'] ?? null;
        if (!\is_array($requests) || !array_is_list($requests)) {
            throw ProtocolException::malformed(self::MINT, "'m' must be a list of requests");
        }

        if (\count($requests) > self::MAX_MINTS_PER_RENDER) {
            throw ProtocolException::malformed(
                self::MINT,
                'more than ' . self::MAX_MINTS_PER_RENDER . ' tokens requested in one render',
            );
        }

        $out = [];
        $seen = [];

        foreach ($requests as $request) {
            if (!\is_array($request)) {
                throw ProtocolException::malformed(self::MINT, 'each request must be a map');
            }

            $id = $request['id'] ?? null;
            $component = $request['component'] ?? null;
            $props = $request['props'] ?? [];

            // Strictly an integer, as readQueryBatch already requires for the
            // identical field. Accepting numeric strings and casting made
            // "1", "01" and "0001" one id: each was signed and charged against
            // the cap, one token came back, and the other boundaries' promises
            // waited forever.
            if (!\is_int($id) || $id < 0) {
                throw ProtocolException::malformed(self::MINT, "'id' must be a non-negative integer");
            }
            if (isset($seen[$id])) {
                throw ProtocolException::malformed(self::MINT, "duplicate id {$id}");
            }
            $seen[$id] = true;

            if (!\is_string($component) || $component === '') {
                throw ProtocolException::malformed(self::MINT, "'component' must be a non-empty string");
            }
            if (!\is_array($props) || array_is_list($props) && $props !== []) {
                throw ProtocolException::malformed(self::MINT, "'props' must be a map");
            }

            $out[] = ['id' => $id, 'component' => $component, 'props' => $props];
        }

        return $out;
    }

    /**
     * The code, digest and message of a report frame. Anything not of the
     * shape this side expects is dropped rather than logged.
     *
     * @param array<string, mixed> $frame
     * @return array{code: string, digest: string|null, message: string|null}
     */
    public static function readReport(array $frame): array
    {
        $code = $frame['code'] ?? null;
        $digest = $frame['digest'] ?? null;
        $message = $frame['message'] ?? null;

        return [
            'code' => \is_string($code) && preg_match('/^[a-z_]{1,64}$/D', $code) === 1 ? $code : 'render_failed',
            'digest' => \is_string($digest) && preg_match(self::DIGEST_SHAPE, $digest) === 1 ? $digest : null,
            'message' => \is_string($message) ? $message : null,
        ];
    }

    /**
     * Rejects any frame the renderer is not allowed to originate.
     *
     * @param array<string, mixed> $frame
     * @return non-empty-string the validated frame type
     */
    public static function assertFromRenderer(array $frame): string
    {
        $type = $frame['t'] ?? null;
        if (!\is_string($type) || $type === '') {
            throw ProtocolException::unknownType($type);
        }
        if (!\in_array($type, self::RENDERER_TO_PHP, true)) {
            throw ProtocolException::wrongDirection($type);
        }

        return $type;
    }

    /**
     * Pulls the `{id, name, args}` calls out of a query frame, checking shape
     * before any of them reach the registry.
     *
     * @param array<string, mixed> $frame
     * @return list<array{id: int, name: string, args: array<string, mixed>}>
     */
    public static function readQueryBatch(array $frame): array
    {
        $calls = $frame['q'] ?? null;
        if (!\is_array($calls) || !array_is_list($calls)) {
            throw ProtocolException::malformed(self::QUERY, "'q' must be a list of calls");
        }

        $out = [];
        foreach ($calls as $call) {
            if (!\is_array($call)) {
                throw ProtocolException::malformed(self::QUERY, 'each call must be a map');
            }
            $id = $call['id'] ?? null;
            $name = $call['name'] ?? null;
            $args = $call['args'] ?? [];

            if (!\is_int($id)) {
                throw ProtocolException::malformed(self::QUERY, "call 'id' must be an int");
            }
            if (!\is_string($name) || $name === '') {
                throw ProtocolException::malformed(self::QUERY, "call 'name' must be a non-empty string");
            }
            if (!\is_array($args)) {
                throw ProtocolException::malformed(self::QUERY, "call 'args' must be a map");
            }

            /** @var array<string, mixed> $args */
            $out[] = ['id' => $id, 'name' => $name, 'args' => $args];
        }

        return $out;
    }
}
