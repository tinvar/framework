<?php

declare(strict_types=1);

namespace Tinvar\Dev;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\DevOverlay;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\RawStatement;
use Tinvar\Render\FrameTap;
use Tinvar\Render\RenderFailed;
use Tinvar\Render\RenderResult;
use Tinvar\Render\TokenError;
use Tinvar\Support\DevMode;
use Tinvar\Support\Signer;

/**
 * The dev toolbar: each request's props as the renderer was given them, its
 * timings, its query ticks and every frame of its renders decoded -- shown
 * over the page in dev, and in dev only.
 *
 * tinvar-spec.md, "Operations and tooling": "per-request props ...,
 * handler, PHP and renderer timings, follow-up requests grouped under the
 * visit, redaction of secrets, gated outside local"; "Observability":
 * "Per-render tick panel" and "Socket timing: time in PHP, time in
 * renderer, time streaming"; and "Dev and test tooling": the dev helpers
 * exist only under `tinvar dev`, and "bind to loopback with a per-run token".
 *
 * How it stays out of production, each layer on its own:
 *
 *   - It exists only under `tinvar dev`, which makes a private directory for
 *     the run, puts a key in it, and names it in TINVAR_DEV_TOOLBAR. That
 *     variable beside any production marker refuses the process -- as
 *     TINVAR_DEV=1 does (DevMode, ADR 0034) -- whether or not dev mode is on.
 *   - A Kernel not built for dev refuses one, so there is no production
 *     configuration it can be put into.
 *   - Its script and style are the framework's, inlined by PHP into a dev
 *     response under that response's nonce; no application bundle imports
 *     them, so no build of one contains them.
 *   - Its endpoint answers a loopback peer naming a loopback host only, and
 *     only with a token signed by this run's key and bound to the session
 *     and user asking (ToolbarToken); what it hands back has had its secrets
 *     taken out (Recording).
 *
 * It sees renders through the channel's FrameTap -- the application passes
 * it to its RenderChannel -- and requests through the Kernel, which begins a
 * recording as a request arrives, tells it of a failed render, and finishes
 * it before the request scope resets, while the session and the user it
 * belongs to are still known.
 */
final class DevToolbar implements FrameTap
{
    /** Names the run's private directory; set by `tinvar dev`, and by nothing else. */
    public const string ENV = 'TINVAR_DEV_TOOLBAR';

    /** The run's key, in that directory. */
    public const string KEY_FILE = 'toolbar.key';

    /** The endpoint: the requests list, and `/{id}` for one. */
    public const string PATH = '/_dev/toolbar';

    /** The header the toolbar's fetches carry their token in. */
    public const string HEADER = 'x-tinvar-toolbar';

    /** The element the toolbar draws itself into, and the prefix of its data block's id. */
    public const string ID = 'tinvar-dev-toolbar';

    private const string ASSETS = __DIR__ . '/../../resources/dev-toolbar';

    private readonly Signer $signer;
    private readonly RecordingStore $store;

    /** The request being recorded; null between requests and for the endpoint's own. */
    private ?Recording $current = null;

    /**
     * @param string $directory the run's private directory, where recordings are kept
     * @param string $key the run's key, which signs the toolbar's tokens and keys its bindings
     * @param \Closure(Request): string $sessionId the request's session id, '' without one
     * @param string $build the build tokens are bound to
     */
    public function __construct(
        string $directory,
        #[\SensitiveParameter]
        string $key,
        private readonly \Closure $sessionId,
        private readonly string $build = 'dev',
    ) {
        $this->signer = new Signer($key, ToolbarToken::PURPOSE);
        $this->store = new RecordingStore($directory);
    }

    /**
     * The toolbar `tinvar dev` asked for, or null when it asked for none.
     *
     * Refused beside a production marker first, before anything else is
     * read: TINVAR_DEV_TOOLBAR in production is a dev setting copied where
     * it does not belong, and the process stops rather than serving. Then
     * only in dev mode (DevMode); and then only from a directory and a key
     * that are this user's alone, since what the toolbar keeps there is
     * what each page was given.
     *
     * @param \Closure(Request): string $sessionId
     * @param array<string, string>|null $env the environment; the process's own by default
     * @param bool|null $dev whether the process is serving in dev; read from $env by default
     * @throws \RuntimeException when refused
     */
    public static function fromEnvironment(\Closure $sessionId, ?array $env = null, ?bool $dev = null, string $build = 'dev'): ?self
    {
        $env ??= array_filter(getenv(), \is_string(...));
        $directory = $env[self::ENV] ?? '';
        if ($directory === '') {
            return null;
        }

        $marker = DevMode::productionMarker($env);
        if ($marker !== null) {
            throw new \RuntimeException(
                self::ENV . " is refused: {$marker}=production. The dev toolbar shows every request's props and "
                . 'frames, and never runs in production. Unset ' . self::ENV . '; only `tinvar dev` sets it.',
            );
        }

        if (!($dev ?? DevMode::fromEnvironment($env))) {
            return null;
        }

        return new self($directory, self::readKey($directory), $sessionId, $build);
    }

    /**
     * Puts a fresh key for a run of the toolbar into $directory, a private
     * directory `tinvar dev` has just made (PrivateDirectory).
     */
    public static function prepare(string $directory): void
    {
        self::assertPrivate($directory, 'directory');
        $file = "{$directory}/" . self::KEY_FILE;
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            throw new \RuntimeException("Could not create {$file}: something is already there.");
        }
        try {
            chmod($file, 0600);
            fwrite($handle, bin2hex(random_bytes(32)));
        } finally {
            fclose($handle);
        }
    }

    // ------------------------------------------------------------ recording

    /**
     * Starts recording $request -- from loopback only, and never the
     * endpoint's own requests, which would fill the list with themselves.
     */
    public function begin(Request $request): void
    {
        $this->current = $this->matches($request) || !Loopback::request($request) ? null : new Recording($request);
    }

    public function frame(string $direction, array $frame): void
    {
        $this->current?->frame($direction, $frame);
    }

    public function rendered(RenderResult $result): void
    {
        $this->current?->rendered($result);
    }

    public function failed(RenderFailed $event): void
    {
        $this->current?->failed($event->failure, $event->digest);
    }

    /**
     * Raw SQL on a tenant table, sent while the request was recorded: what
     * the Kernel hands Orm::flagRawStatements() when it has a toolbar.
     */
    public function rawStatement(RawStatement $statement): void
    {
        $this->current?->rawStatement($statement);
    }

    /**
     * Keeps the request's recording under the session and user it ran for,
     * and draws the toolbar into a document response. Called before the
     * request scope resets: who the user is, and their auth epoch, are read
     * now.
     *
     * Nothing is kept or drawn for a request with no session: there would be
     * nothing to bind it to, and a recording every session could read is one
     * nobody should.
     */
    public function finish(Request $request, Response $response, AuthContext $auth): Response
    {
        $recording = $this->current;
        $this->current = null;
        $session = ($this->sessionId)($request);
        if ($recording === null || $session === '') {
            return $response;
        }

        $binding = ToolbarToken::for($this->signer, $session, $auth->id(), $auth->epoch(), $this->build);
        $this->store->save($recording->finish($response->status, $binding->sessionHash, $binding->authHash));

        return $this->drawn($request, $response, $binding, $recording->id);
    }

    /** Forgets the request in hand: one that escaped handle() is not finished. */
    public function end(): void
    {
        $this->current = null;
    }

    // ------------------------------------------------------------- endpoint

    public function matches(Request $request): bool
    {
        return $request->path === self::PATH || str_starts_with($request->path, self::PATH . '/');
    }

    /**
     * The endpoint: `GET /_dev/toolbar`, the requests recorded for the session
     * and user asking, newest first; `GET /_dev/toolbar/{id}`, one of them
     * whole. Run behind the application's global middleware, so the session
     * it opened and the user it signed in are what the token is checked
     * against.
     *
     * Not from loopback, it is not here: 404, as for a build that has none.
     * Without a token this run signed for this session and user: 403.
     */
    public function respond(Request $request, AuthContext $auth): Response
    {
        if (!Loopback::request($request)) {
            return self::json(404, ['error' => 'not_found']);
        }
        if ($request->method !== 'GET') {
            return self::json(405, ['error' => 'method_not_allowed'])->withHeader('allow', 'GET');
        }

        $session = ($this->sessionId)($request);
        if ($session === '') {
            return self::json(403, ['error' => 'no_session']);
        }
        try {
            $presented = ToolbarToken::decode($request->header(self::HEADER) ?? '', $this->signer);
        } catch (TokenError) {
            return self::json(403, ['error' => 'invalid_token']);
        }
        $current = ToolbarToken::for($this->signer, $session, $auth->id(), $auth->epoch(), $this->build);
        if (!$presented->admits($current)) {
            return self::json(403, ['error' => 'invalid_token']);
        }

        $rest = substr($request->path, \strlen(self::PATH));
        if ($rest === '') {
            return self::json(200, ['requests' => $this->store->list($current->sessionHash, $current->authHash)]);
        }

        $found = preg_match('#^/([0-9a-f]{16})\z#', $rest, $m) === 1
            ? $this->store->find($m[1], $current->sessionHash, $current->authHash)
            : null;

        return $found === null ? self::json(404, ['error' => 'not_found']) : self::json(200, ['request' => $found]);
    }

    // -------------------------------------------------------------- drawing

    /**
     * The document with the toolbar at the end of its body: its data block,
     * and its script under the nonce the response's own policy names.
     *
     * Only a whole HTML document, from loopback, whose policy names a nonce:
     * a script with no nonce would be refused by the policy, and one drawn
     * into a response with no policy would be the one script on the page
     * nothing vouched for. A client navigation's payload carries no
     * toolbar; the one already drawn reads that request with the rest.
     */
    private function drawn(Request $request, Response $response, ToolbarToken $binding, string $recording): Response
    {
        $nonce = DevOverlay::nonceOf($response->header('content-security-policy'));
        if ($nonce === null
            || $response->isStreaming()
            || $request->wantsRscPayload()
            || !Loopback::request($request)
            || !str_starts_with(strtolower($response->header('content-type') ?? ''), 'text/html')
            || stripos($response->body(), '</body>') === false) {
            return $response;
        }

        $markup = self::markup($nonce, [
            'endpoint' => self::PATH,
            'header' => self::HEADER,
            'token' => $binding->encode($this->signer),
            'request' => $recording,
        ]);

        return Response::make($response->status, DevOverlay::into($response->body(), $markup), $response->headers())
            ->withCookiesOf($response);
    }

    /**
     * The toolbar's markup: a data block the script reads its settings
     * from, which is never run and so takes no nonce, and the script, which
     * carries the nonce and applies it to the style it makes.
     *
     * @param array<string, string> $config
     */
    public static function markup(string $nonce, array $config): string
    {
        $script = self::asset('toolbar.js');
        $config['style'] = self::asset('toolbar.css');

        // Escaped as the page metadata is: no `<`, `>` or `&` survives, so
        // nothing in it can close the element or open another.
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $nonceAttribute = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<script type="application/json" id="' . self::ID . '-config">' . $json . '</script>'
            . '<script nonce="' . $nonceAttribute . '">' . $script . '</script>';
    }

    private static function asset(string $name): string
    {
        $contents = file_get_contents(self::ASSETS . "/{$name}");
        // Inlined into a <script>: a closing tag or a comment opener in it
        // would end the element, or change how the parser reads the rest.
        if (!\is_string($contents) || preg_match('/<\/script|<!--/i', $contents) === 1) {
            throw new \LogicException("The dev toolbar's {$name} is missing, or cannot be inlined into a script element.");
        }

        return $contents;
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): Response
    {
        return Response::make(
            $status,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION),
            [
                'content-type' => 'application/json; charset=utf-8',
                'cache-control' => 'no-store',
                'x-content-type-options' => 'nosniff',
                'vary' => 'Cookie',
            ],
        );
    }

    // ------------------------------------------------------------- the run

    private static function readKey(string $directory): string
    {
        self::assertPrivate($directory, 'directory');
        $file = "{$directory}/" . self::KEY_FILE;
        self::assertPrivate($file, 'file');

        $key = file_get_contents($file);
        if (!\is_string($key) || preg_match('/^[0-9a-f]{64}\z/', $key) !== 1) {
            throw new \RuntimeException("{$file} is not a key `tinvar dev` wrote.");
        }

        return $key;
    }

    /**
     * That $path is this user's alone: no symlink, owned by this user, and
     * nobody else may read, write or enter it.
     */
    private static function assertPrivate(string $path, string $kind): void
    {
        clearstatcache(true, $path);
        $exists = $kind === 'directory' ? is_dir($path) : is_file($path);
        if (!str_starts_with($path, '/') || is_link($path) || !$exists) {
            throw new \RuntimeException("The dev toolbar's {$kind} {$path} is not one `tinvar dev` made.");
        }

        $stat = @stat($path);
        if ($stat === false
            || (\function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || ($stat['mode'] & 0077) !== 0) {
            throw new \RuntimeException("The dev toolbar's {$kind} {$path} is not private to this user; refusing it.");
        }
    }
}
