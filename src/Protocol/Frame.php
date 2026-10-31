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

    /**
     * An email template to render: its name and props, nothing else. The
     * renderer answers with the HTML as chunks and the plain-text part in a
     * meta frame (`{t: 'meta', mail: {text}}`), and calls no query: a mail
     * render has an empty allowlist, since everything it shows is in its
     * props.
     */
    public const string MAIL = 'mail';

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

    /**
     * A health check, alone on its connection, and its one-frame answer.
     * renderer:check and the pool's supervisor ask it of each renderer: an
     * answer proves the event loop turns, which a connect alone does not,
     * since the kernel accepts connections for a process that is stuck. It
     * carries nothing of any request, and a render may not send it.
     */
    public const string HEALTH = 'health';
    public const string HEALTHY = 'healthy';

    /** @var list<string> */
    public const array PHP_TO_RENDERER = [self::RENDER, self::FRAGMENT, self::RESULT, self::MINTED, self::MAIL, self::HEALTH];

    /** @var list<string> */
    public const array RENDERER_TO_PHP = [
        self::CHUNK,
        self::QUERY,
        self::META,
        self::MINT,
        self::REPORT,
        self::DONE,
        self::ERROR,
        self::HEALTHY,
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
     * @param array<array-key, string>|null $segments for a partial reload, the
     *                                   segments to render alone -- `$loaderData`
     *                                   holds only theirs (ADR 0015); sent as a
     *                                   list whatever its keys
     * @param ?string              $csrfToken the session's CSRF token, for the
     *                                   hidden field <Form> renders so a form
     *                                   posts before JavaScript loads. Never in
     *                                   stored output: pass it only for a render
     *                                   made for one request.
     * @param array<string, mixed> $flash what a form posted without script left
     *                                   for this page: its errors, its input, a
     *                                   flash -- see FlashStore
     * @param list<HeadTag>        $head the application's fallback title and meta
     *                                   tags, which a component's own replace
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
        ?array $segments = null,
        ?string $csrfToken = null,
        array $flash = [],
        array $head = [],
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

        if ($segments !== null) {
            $frame['segments'] = array_values($segments);
        }

        // Absent rather than empty: a render with nothing for its forms
        // sends what it always sent.
        if ($csrfToken !== null) {
            $frame['csrf'] = $csrfToken;
        }
        if ($flash !== []) {
            $frame['flash'] = $flash;
        }
        if ($head !== []) {
            $frame['head'] = array_map(static fn (HeadTag $tag): array => $tag->toFrame(), $head);
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
     * @param array<string, mixed> $props already encoded: Data objects, scalars, lists and maps
     * @param array<string, mixed> $links the message's LinkPolicy (toFrame()): what
     *        @tinvar/mail holds its links and images to, and the attachments it may list
     * @return array<string, mixed>
     */
    public static function mail(string $template, array $props, string $requestId, array $links = ['origin' => null, 'images' => []]): array
    {
        return ['t' => self::MAIL, 'template' => $template, 'props' => $props, 'rid' => $requestId, 'links' => $links];
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
    public static function health(): array
    {
        return ['t' => self::HEALTH];
    }

    /**
     * What a renderer said of itself, checked field by field: the renderer
     * is the semi-trusted side, and these numbers end up on an operator's
     * screen.
     *
     * @param array<string, mixed> $frame
     * @return array{pid: int, inFlight: int, served: int}
     */
    public static function readHealthy(array $frame): array
    {
        if (($frame['t'] ?? null) !== self::HEALTHY) {
            throw new ProtocolException("A health check must be answered with a 'healthy' frame.");
        }

        $read = [];
        foreach (['pid', 'inFlight', 'served'] as $field) {
            $value = $frame[$field] ?? null;
            if (!\is_int($value) || $value < 0) {
                throw new ProtocolException("A 'healthy' frame must carry a whole number in '{$field}'.");
            }
            $read[$field] = $value;
        }

        return ['pid' => $read['pid'], 'inFlight' => $read['inFlight'], 'served' => $read['served']];
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
     * The code, digest and message of a report frame, and where it
     * happened. Anything not of the shape this side expects is dropped
     * rather than logged; what is kept of the message and the place is
     * the renderer exception's to decide, under debug only.
     *
     * @param array<string, mixed> $frame
     * @return array{code: string, digest: string|null, message: string|null, stack: string|null, file: string|null, line: int|null}
     */
    public static function readReport(array $frame): array
    {
        $code = $frame['code'] ?? null;
        $digest = $frame['digest'] ?? null;
        $message = $frame['message'] ?? null;
        $stack = $frame['stack'] ?? null;
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? null;

        return [
            'code' => \is_string($code) && preg_match('/^[a-z_]{1,64}$/D', $code) === 1 ? $code : 'render_failed',
            'digest' => \is_string($digest) && preg_match(self::DIGEST_SHAPE, $digest) === 1 ? $digest : null,
            'message' => \is_string($message) ? $message : null,
            'stack' => \is_string($stack) ? $stack : null,
            'file' => \is_string($file) ? $file : null,
            'line' => \is_int($line) && $line > 0 ? $line : null,
        ];
    }

    /**
     * The phases a renderer may say it timed, on its `done` frame (ADR
     * 0128): when its Flight payload was written, when its HTML shell was
     * ready, the HTML after the shell, and an email's render. A fixed list,
     * since each becomes the name of a span: a renderer cannot name one.
     */
    public const array PHASES = ['flight', 'shell', 'ssr', 'mail'];

    /**
     * The longest a phase may say it took, in milliseconds: past any
     * render's deadline, so a phase claiming more is not a measurement.
     */
    public const float MAX_PHASE_MS = 3_600_000.0;

    /**
     * The phases a `done` frame carries in `ph`, each `{n, s, e}`: a name
     * from PHASES, and its start and end in milliseconds from the moment
     * the renderer was handed the render. None when it carries none.
     *
     * The renderer's numbers, so held to their shape -- each name at most
     * once, a finite start no later than its end, both within MAX_PHASE_MS
     * -- and a frame that breaks it is a protocol violation, as any
     * malformed frame is. They are only ever drawn as spans, within the
     * render's own, by the render channel's telemetry.
     *
     * @param array<string, mixed> $frame
     * @return list<array{name: string, start: float, end: float}>
     */
    public static function readPhases(array $frame): array
    {
        if (!\array_key_exists('ph', $frame)) {
            return [];
        }

        $phases = $frame['ph'];
        if (!\is_array($phases) || !array_is_list($phases) || \count($phases) > \count(self::PHASES)) {
            throw ProtocolException::malformed(self::DONE, "'ph' must be a list of at most " . \count(self::PHASES) . ' phases');
        }

        $out = [];
        $seen = [];
        foreach ($phases as $phase) {
            if (!\is_array($phase) || array_diff_key($phase, ['n' => 0, 's' => 0, 'e' => 0]) !== []) {
                throw ProtocolException::malformed(self::DONE, 'each phase must be a map of n, s and e');
            }
            $name = $phase['n'] ?? null;
            $start = $phase['s'] ?? null;
            $end = $phase['e'] ?? null;
            if (!\is_string($name) || !\in_array($name, self::PHASES, true) || isset($seen[$name])) {
                throw ProtocolException::malformed(self::DONE, "a phase's name must be one of " . implode(', ', self::PHASES) . ', each once');
            }
            $seen[$name] = true;
            if (!\is_int($start) && !\is_float($start) || !\is_int($end) && !\is_float($end)
                || !is_finite((float) $start) || !is_finite((float) $end)
                || $start < 0 || $start > $end || $end > self::MAX_PHASE_MS) {
                throw ProtocolException::malformed(self::DONE, "a phase's start and end must be milliseconds, the start no later than the end");
            }

            $out[] = ['name' => $name, 'start' => (float) $start, 'end' => (float) $end];
        }

        return $out;
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
